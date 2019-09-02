import os


class Config(object):
    SERVER_NAME = os.environ.get('WEBSITE_URL')
