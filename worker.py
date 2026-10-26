"""Keep the real account connected; local status IPC only until a target is supplied."""
import asyncio
import contextlib
import fcntl
import json
import logging
import os
import signal
import time

from .core import (PRIVATE, BridgeError, TestSlots, auth_state, client, config,
                   credentials_ready, prepare, session_lock, write_json)

logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
logging.getLogger('telethon').setLevel(logging.CRITICAL)

class AccountBridge:
    def __init__(self):
        self.connection = None
        self.session_guard = None
        self.active_credentials = None
        self.state = 'login_required'
        self.last_error = None
        self.retry_at = 0
        self.last_verified = 0
        self.slots = TestSlots()
        self.operation_lock = asyncio.Lock()
        self.stopped = asyncio.Event()

    async def disconnect(self):
        if self.connection is not None:
            try:
                await asyncio.wait_for(self.connection.disconnect(), 10)
            except Exception:
                pass
            self.connection = None
        if self.session_guard is not None:
            self.session_guard.__exit__(None, None, None)
            self.session_guard = None
        self.active_credentials = None
        self.last_verified = 0

    def status(self):
        settings = config()
        authorized = auth_state(settings) == 'authorized'
        return {'account_state': self.state, 'credentials_configured': credentials_ready(settings),
                'authorized': authorized, 'connected': bool(self.connection and self.connection.is_connected()),
                'target_configured': bool(settings['target_bot']),
                # The external bot adapter is deliberately absent until the target is specified.
                'tests_available': False, 'test_seconds': 600, 'daily_attempts': 1,
                'daily_timezone': settings['timezone'], 'last_error': self.last_error,
                'retry_after': max(0, self.retry_at - int(time.time())), **self.slots.status()}

    async def maintain(self):
        while not self.stopped.is_set():
            try:
                async with self.operation_lock:
                    settings = config()
                    state = auth_state(settings)
                    if state != 'authorized':
                        await self.disconnect()
                        self.state, self.last_error = state, None
                    elif time.time() >= self.retry_at:
                        fingerprint = (settings['api_id'], settings['api_hash'])
                        if self.active_credentials != fingerprint:
                            await self.disconnect()
                        if self.connection is None:
                            guard = session_lock()
                            guard.__enter__()
                            self.session_guard = guard
                            self.connection = client(settings)
                            self.active_credentials = fingerprint
                        self.state = 'connecting'
                        if not self.connection.is_connected():
                            await asyncio.wait_for(self.connection.connect(), 20)
                            self.last_verified = 0
                        if time.time() - self.last_verified >= 60:
                            if not await asyncio.wait_for(self.connection.is_user_authorized(), 15):
                                (PRIVATE / 'authorized.json').unlink(missing_ok=True)
                                await self.disconnect()
                                self.state = 'login_required'
                            else:
                                me = await asyncio.wait_for(self.connection.get_me(), 15)
                                expected = json.loads((PRIVATE / 'authorized.json').read_text())['user_id']
                                if me is None or me.bot or me.id != expected:
                                    (PRIVATE / 'authorized.json').unlink(missing_ok=True)
                                    await self.disconnect()
                                    self.state = 'login_required'
                                else:
                                    self.last_verified = int(time.time())
                        if self.connection is not None:
                            self.state, self.last_error = 'connected', None
                    write_json('status.json', self.status() | {'updated_at': int(time.time())})
            except Exception as error:
                name = error.code if isinstance(error, BridgeError) else type(error).__name__
                self.last_error = name
                self.state = 'connection_error'
                delay = max(5, int(getattr(error, 'seconds', 15)))
                self.retry_at = int(time.time()) + delay
                if name in ('AuthKeyUnregisteredError', 'SessionRevokedError', 'UserDeactivatedError', 'UserDeactivatedBanError', 'AuthKeyDuplicatedError'):
                    (PRIVATE / 'authorized.json').unlink(missing_ok=True)
                    self.state = 'login_required'
                await self.disconnect()
                # Never log exception strings, credentials, phone numbers or account messages.
                logging.warning('Account connection needs attention (%s).', name)
                write_json('status.json', self.status() | {'updated_at': int(time.time())})
            try:
                await asyncio.wait_for(self.stopped.wait(), timeout=5)
            except asyncio.TimeoutError:
                pass

    async def request(self, reader, writer):
        try:
            data = await asyncio.wait_for(reader.readline(), 3)
            if len(data) > 4096:
                raise BridgeError('request_too_large')
            request = json.loads(data)
            if not isinstance(request, dict) or request.get('action') != 'status':
                raise BridgeError('unsupported_action')
            result = {'ok': True, 'result': self.status()}
        except Exception as error:
            result = {'ok': False, 'error': error.code if isinstance(error, BridgeError) else 'invalid_request'}
        try:
            writer.write((json.dumps(result) + '\n').encode())
            await writer.drain()
        finally:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

    async def run(self):
        prepare()
        socket = PRIVATE / 'bridge.sock'
        socket.unlink(missing_ok=True)
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGINT, signal.SIGTERM):
            loop.add_signal_handler(sig, self.stopped.set)
        server = await asyncio.start_unix_server(self.request, path=str(socket), limit=4096)
        socket.chmod(0o600)
        task = asyncio.create_task(self.maintain())
        logging.info('Private account bridge started. No test target is active.')
        try:
            await self.stopped.wait()
        finally:
            server.close()
            await server.wait_closed()
            task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await task
            await self.disconnect()
            socket.unlink(missing_ok=True)
            self.slots.db.close()


def main():
    prepare()
    with (PRIVATE / 'worker.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise SystemExit('Account bridge is already running.') from None
        asyncio.run(AccountBridge().run())

if __name__ == '__main__':
    main()
