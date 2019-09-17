import os

from dotenv import load_dotenv

basedir = os.path.abspath(os.path.dirname(__file__))
load_dotenv(os.path.join(basedir, '.env'))


class Config(object):
    def get_database_uri():
        MYSQL_SERVER = os.environ.get('MYSQL_SERVER')
        MYSQL_USERNAME = os.environ.get('MYSQL_USERNAME')
        MYSQL_PASSWORD = os.environ.get('MYSQL_PASSWORD')
        MYSQL_DATABASE = os.environ.get('MYSQL_DATABASE')
        if MYSQL_SERVER and MYSQL_USERNAME and MYSQL_PASSWORD and MYSQL_PASSWORD:
            database_uri = 'mysql+pymysql://' + \
                           MYSQL_USERNAME + ':' + \
                           MYSQL_PASSWORD + '@' + \
                           MYSQL_SERVER + '/' + \
                           MYSQL_DATABASE
            return database_uri
        else:
            return ''

    SERVER_NAME = os.environ.get('DOMAIN')
    SQLALCHEMY_DATABASE_URI = get_database_uri() or \
        'sqlite:///' + os.path.join(basedir, 'app.db')
    SQLALCHEMY_TRACK_MODIFICATIONS = False

    MAIL_SERVER = os.environ.get('MAIL_SERVER')
    MAIL_PORT = int(os.environ.get('MAIL_PORT') or 587)
    MAIL_USE_TLS = (os.environ.get('MAIL_USE_TLS') == '1' or False)
    MAIL_USERNAME = os.environ.get('MAIL_USERNAME')
    MAIL_PASSWORD = os.environ.get('MAIL_PASSWORD')
    MAIL_DEFAULT_SENDER = "EasyWP Cron <"+MAIL_USERNAME+">"
    MAIL_DEFAULT_RECIPIENT = os.environ.get('MAIL_DEFAULT_RECIPIENT')

    CACHE_TYPE = os.environ.get('CACHE_TYPE') or 'simple'
    DEBUG = (os.environ.get('DEBUG') == '1' or False)
    FAILED_URL_HANDLER = os.environ.get('FAILED_URL_HANDLER') or 'all'
