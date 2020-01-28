import json

import requests
from app import app, db
from app.models import BotUser
from requests.exceptions import RequestException, Timeout, TooManyRedirects


class InvalidMethodException(Exception):
    """Raised when the method supplied to send_request is not supported"""
    pass


class FlockAPI:
    """Flock "EasyWP Debugger Bot" bot

    Send messages regarding failed links to Flock through a bot.

    Variables:
        admin_user_id {str} -- User ID of the admin. Currently, it is
            ID of Artyom Perepelitsa.
        admin_user_token {str} -- Token of the admin user.
        test_channel_id {str} -- ID of the "EasyWP Bot Testing" testing
            channel.
        sme_channel_id {str} -- ID of the "CS: Hosting SME" channel used
            in production.
        bot_id {str} -- ID of EasyWP Debugger Bot.
        bot_token {str} -- Token of the bot.
        flock_base_url {str} -- Flock base API URL
    """
    admin_user_id = app.config['ADMIN_FLOCK_ID']
    admin_user_token = app.config['ADMIN_FLOCK_TOKEN']
    test_channel_id = app.config['TEST_CHANNEL_ID']
    sme_channel_id = app.config['SME_CHANNEL_ID']
    bot_id = app.config['BOT_ID']
    bot_token = app.config['BOT_TOKEN']
    flock_base_url = 'https://api.flock.co/v1/'

    def send_request(method, endpoint, payload):
        """Send an API call to a Flock API enpoint.

        Arguments:
            method {str} -- HTTP method to use for the request.
            endpoint {str} -- API endpoint to send the request to.
            payload {dict} -- Dictionary of the query parameters (GET)
                or data (POST).

        Returns:
            mixed -- JSON response as a dictionary object or False on
                failure.

        Raises:
            InvalidMethodException -- Raised if the method passed is
                not allowed.
        """
        try:
            if method == 'GET' or method == 'get':
                response = requests.get(
                    FlockAPI.flock_base_url+endpoint, params=payload)
            elif method == 'POST' or method == 'post':
                response = requests.post(
                    FlockAPI.flock_base_url+endpoint, data=payload)
            else:
                raise InvalidMethodException('Method is not supported.')
        except Timeout:
            error = "Timeout occurred when accessing " \
                    + FlockAPI.flock_base_url + endpoint \
                    + "Payload was: " + json.dumps(payload)
            result = False
        except TooManyRedirects:
            error = "There is a redirection loop at " \
                    + FlockAPI.flock_base_url + endpoint \
                    + "Payload was: " + json.dumps(payload)
            result = False
        except RequestException:
            error = "Unknown exception occurred when trying to access " \
                    + FlockAPI.flock_base_url + endpoint \
                    + "Payload was: " + json.dumps(payload)
            result = False
        if response.status_code == 200:
            result = response.json()
        else:
            error = str(response.status_code)
            error = FlockAPI.flock_base_url + endpoint + " returned " \
                + error + " status code. Payload was: " \
                + json.dumps(payload)
            result = False
        if not result:
            app.error_logger.error(error)
        return result

    def get(endpoint, params):
        """Send a GET request to a Flock API endpoint

        Arguments:
            endpoint {str} -- API endpoint to send the request to.
            params {dict} -- Dictionary of the query parameters.

        Returns:
            mixed -- JSON response as a dictionary object or False on
                failure.
        """
        return FlockAPI.send_request('GET', endpoint, params)

    def post(endpoint, data):
        """Send a POST request to a Flock API endpoint.

        Arguments:
            endpoint {str} -- API endpoint to send the request to.
            data {dict} -- Dictionary of the POST data.

        Returns:
            mixed -- JSON response as a dictionary object or False on
                failure.
        """
        return FlockAPI.send_request('POST', endpoint, data)

    def get_channels(user_token):
        """Get channels of the Flock user.

        Arguments:
            user_token {str} -- Token of a Flock user.

        Returns:
            mixed -- JSON response as a dictionary object or False on
                failure.
        """
        return FlockAPI.get('channels.list', {'token': user_token})

    def get_channel_id(channel_name, user_token):
        """Get ID of the channel based on its name.

        Arguments:
            channel_name {str} -- Name of a Flock channel.
            user_token {str} -- Token of a Flock user.

        Returns:
            str -- ID of the channel.
        """
        channels = FlockAPI.get_channels(user_token)
        for channel in channels:
            if channel['name'] == channel_name:
                return channel['id']

    def send_message(text, color=None):
        """Send a message to the Flock channel.

        Arguments:
            text {str} -- Text of the message.

        Keyword Arguments:
            color {str} -- HEX value of the color used for the message
                (default: {None}).
        """
        if app.debug:
            group = FlockAPI.test_channel_id
        else:
            group = FlockAPI.sme_channel_id
        if color:
            # the text will be sent as an attachment in the colored message
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
        """Process an API call sent by Flock API to the bot.

        Arguments:
            json_request {dict} -- JSON string as a dictionary object.
        """
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
            app.info_logger.info('The message was received from '
                                 + json_request['message']['from']
                                 + '. The text is:\n'
                                 + json_request['message']['text'] + '\n')
