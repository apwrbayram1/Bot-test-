import argparse
import asyncio
import getpass
import json
import logging
import re
import sys
import time

from .core import (PRIVATE, BridgeError, auth_state, client, config, credentials_ready,
                   prepare, read_json, session_lock, write_json)

logging.getLogger('telethon').setLevel(logging.CRITICAL)

def secret(prompt):
    if not sys.stdin.isatty():
        raise BridgeError('use_stdin_json_for_noninteractive_login')
    return getpass.getpass(prompt)

async def perform(action, values):
    prepare()
    settings = config()
    if action == 'status':
        print(json.dumps({'account_state': auth_state(settings), 'target_configured': bool(settings['target_bot']), 'test_enabled': settings['enabled']}))
        return
    with session_lock():
        if action == 'configure':
            if (PRIVATE / 'authorized.json').exists():
                raise BridgeError('already_authorized_do_not_replace_credentials')
            api_id = values.get('api_id') if values is not None else input('Telegram API ID: ')
            api_hash = values.get('api_hash') if values is not None else secret('Telegram API hash: ')
            try:
                settings.update(api_id=int(api_id), api_hash=str(api_hash).strip())
            except (TypeError, ValueError):
                raise BridgeError('invalid_api_credentials') from None
            if not credentials_ready(settings):
                raise BridgeError('invalid_api_credentials')
            write_json('config.json', settings)
            print(json.dumps({'ok': True, 'account_state': 'login_required'}))
            return
        connection = client(settings)
        try:
            await asyncio.wait_for(connection.connect(), timeout=20)
            if await connection.is_user_authorized():
                me = await connection.get_me()
                if me.bot:
                    raise BridgeError('a_real_user_account_is_required')
                write_json('authorized.json', {'user_id': me.id, 'authorized_at': int(time.time())})
                (PRIVATE / 'pending.json').unlink(missing_ok=True)
                print(json.dumps({'ok': True, 'account_state': 'authorized'}))
                return
            from telethon.errors import SessionPasswordNeededError
            if action == 'request-code':
                phone = values.get('phone', '') if values is not None else secret('Phone number with country code: ')
                phone = re.sub(r'[\s()-]', '', str(phone))
                if not re.fullmatch(r'\+[1-9][0-9]{6,14}', phone):
                    raise BridgeError('phone_requires_country_code')
                pending = read_json('pending.json', {}) or {}
                if pending.get('requested_at', 0) + 60 > time.time():
                    raise BridgeError('wait_before_requesting_another_code', int(pending['requested_at'] + 60 - time.time()))
                sent = await connection.send_code_request(phone)
                now = int(time.time())
                write_json('pending.json', {'phone': phone, 'phone_code_hash': sent.phone_code_hash,
                                          'state': 'code_required', 'requested_at': now, 'expires': now + 600})
                print(json.dumps({'ok': True, 'account_state': 'code_required', 'delivery': type(sent.type).__name__}))
            elif action in ('sign-in', 'password'):
                pending = read_json('pending.json', {}) or {}
                if pending.get('expires', 0) <= time.time():
                    raise BridgeError('login_request_expired_request_a_new_code')
                if action == 'sign-in':
                    code = values.get('code', '') if values is not None else secret('Telegram login code: ')
                    code = re.sub(r'\s', '', str(code))
                    if not re.fullmatch(r'[0-9]{4,10}', code):
                        raise BridgeError('invalid_login_code')
                    try:
                        await connection.sign_in(phone=pending['phone'], code=code, phone_code_hash=pending['phone_code_hash'])
                    except SessionPasswordNeededError:
                        pending['state'] = 'password_required'
                        pending['expires'] = int(time.time()) + 600
                        write_json('pending.json', pending)
                        print(json.dumps({'ok': True, 'account_state': 'password_required'}))
                        return
                else:
                    if pending.get('state') != 'password_required':
                        raise BridgeError('login_code_required_first')
                    password = values.get('password', '') if values is not None else secret('Telegram two-step verification password: ')
                    if not isinstance(password, str) or not password:
                        raise BridgeError('password_required')
                    await connection.sign_in(password=password)
                me = await connection.get_me()
                if me.bot:
                    raise BridgeError('a_real_user_account_is_required')
                write_json('authorized.json', {'user_id': me.id, 'authorized_at': int(time.time())})
                (PRIVATE / 'pending.json').unlink(missing_ok=True)
                print(json.dumps({'ok': True, 'account_state': 'authorized'}))
        finally:
            await connection.disconnect()

def main():
    parser = argparse.ArgumentParser(description='Private account connection; secrets are never command-line arguments.')
    parser.add_argument('action', choices=['configure', 'request-code', 'sign-in', 'password', 'status'])
    parser.add_argument('--stdin-json', action='store_true', help='Read sensitive input as one JSON object on stdin')
    args = parser.parse_args()
    try:
        values = json.load(sys.stdin) if args.stdin_json else None
        if values is not None and not isinstance(values, dict):
            raise BridgeError('input_must_be_a_json_object')
        asyncio.run(perform(args.action, values))
    except BridgeError as error:
        print(json.dumps({'ok': False, 'error': error.code, 'retry_after': error.retry_after}))
        raise SystemExit(1)
    except Exception as error:
        # Error strings and tracebacks may contain phone numbers or auth material.
        name = type(error).__name__
        if name == 'PhoneCodeExpiredError':
            (PRIVATE / 'pending.json').unlink(missing_ok=True)
        output = {'ok': False, 'error': name}
        if name == 'FloodWaitError':
            output['retry_after'] = int(error.seconds)
        print(json.dumps(output))
        raise SystemExit(1)
