import requests
from requests.exceptions import Timeout, TooManyRedirects, RequestException
from app import app, db
from app.models import BotUser
import json


class InvalidMethodException(Exception):
    """Raised when the method supplied to send_request is not supported"""
    pass


class FlockAPI:
    admin_user_id = 'moved to .env'
    admin_user_token = 'moved to .env'
    test_group_id = 'moved to .env'
    sme_group_id = 'moved to .env'
    bot_id = 'moved to .env'
    bot_token = 'moved to .env'
    flock_main_url = 'https://api.flock.co/v1/'

    def send_request(method, endpoint, payload):
        try:
            if method == 'GET' or method == 'get':
                response = requests.get(
                    FlockAPI.flock_main_url+endpoint, params=payload)
            elif method == 'POST' or method == 'post':
                response = requests.post(
                    FlockAPI.flock_main_url+endpoint, data=payload)
            else:
                raise InvalidMethodException('Method is not supported.')
        except Timeout:
            error = "Timeout occurred when accessing " + \
                      FlockAPI.flock_main_url + endpoint
            result = False
        except TooManyRedirects:
            error = "There is a redirection loop at " + \
                      FlockAPI.flock_main_url + endpoint
            result = False
        except RequestException:
            error = "Unknown exception occurred when trying to access " + \
                      FlockAPI.flock_main_url + endpoint
            result = False
        if response.status_code == 200:
            result = response.json()
        else:
            error = str(response.status_code)
            error = FlockAPI.flock_main_url + endpoint + \
                " returned " + error + " status code."
            result = False
        if not result:
            app.logger.error(error)
        return result

    def get(endpoint, params):
        return FlockAPI.send_request('GET', endpoint, params)

    def post(endpoint, data):
        return FlockAPI.send_request('POST', endpoint, data)

    def get_channels(user_token):
        return FlockAPI.get('channels.list', {'token': user_token})

    def get_channel_id(channel_name, user_token):
        channels = FlockAPI.get_channels(user_token)
        for channel in channels:
            if channel['name'] == channel_name:
                return channel['id']

    def send_message(text, color=None, testing=True):
        if testing:
            group = FlockAPI.test_group_id
        else:
            group = FlockAPI.sme_group_id
        if color:
            plain_text = ''
        else:
            plain_text = text
        payload = {
                    'to': group,
                    'token': FlockAPI.bot_token,
                    'onBehalfOf': FlockAPI.admin_user_id,
                    'text': plain_text,
                }
        if color:
            payload['attachments'] = json.dumps([{
                'color': color,
                'views': {
                    'flockml': '<flockml>' + text + '</flockml>'
                }
            }])
        FlockAPI.post('chat.sendMessage', payload)

    def process(json_request):
        if json_request['name'] == 'app.install':
            bot_user = BotUser(user_id=json_request['userId'],
                               token=json_request['token'])
            db.session.add(bot_user)
            db.session.commit()
            return {
                'status': 'success',
                'message': "App successfully installed."
            }

        elif json_request['name'] == 'app.uninstall':
            bot_user = BotUser.query.filter_by(
                user_id=json_request['userId']).first()
            db.session.delete(bot_user)
            db.session.commit()
            return {
                'status': 'success',
                'message': "App successfully uninstalled."
            }

        elif json_request['name'] == 'chat.receiveMessage':
            pass
