import os

from dotenv import load_dotenv

basedir = os.path.abspath(os.path.dirname(__file__))
load_dotenv(os.path.join(basedir, '.env'))


class Config(object):
    """
    Flask configuration class.

    Variables:
        SERVER_NAME {str} -- Domain name used by the application
        SQLALCHEMY_DATABASE_URI {str} -- URI to make a database
            connection through SQLAlchemy
        SQLALCHEMY_TRACK_MODIFICATIONS {bool} -- Use SQLAlchemy events
            system (models_commited & before_models_commited) if True
        MAIL_SERVER {str} -- Outgoing mail server
        MAIL_PORT {int} -- SMTP port
        MAIL_USE_TLS {bool} -- Use secure TLS connection if True. SSL
            is not supported.
        MAIL_USERNAME {str} -- Username for the SMTP authentication
        MAIL_PASSWORD {str} -- Password for the SMTP authentication
        MAIL_DEFAULT_SENDER {str} -- Sender of emails. It is the same
            as the SMTP username by default.
        MAIL_DEFAULT_RECIPIENT {str} -- Default recipient of the system
            emails.
        CACHE_TYPE {str} -- Type of cache handler according to
            Flask-Caching https://flask-caching.readthedocs.io/en/latest/#built-in-cache-backends
        DEBUG {bool} -- Enable debug mode if True
        FAILED_URL_HANDLER {str} -- Handler used to report failed
            debugger removals. It can be set to "email", "bot", or "all".
        MAX_QUEUE_LENGTH {int} -- Maximum number of jobs in the queue.
            If it is higher than the value, the app will return
            "Resource is temporarily busy".
        TIME_TO_DELETE {str} -- Time to wait before removing a debugger
            file. This will be interpreted as "at now + <given_value>"
            https://tecadmin.net/one-time-task-scheduling-using-at-commad-in-linux/
    """
    def get_database_uri():
        """Transform MySQL login details into SQLAlchemy database URI.

        Returns:
            str -- SQLAlchemy database URI.
        """
        MYSQL_SERVER = os.environ.get('MYSQL_SERVER')
        MYSQL_USERNAME = os.environ.get('MYSQL_USERNAME')
        MYSQL_PASSWORD = os.environ.get('MYSQL_PASSWORD')
        MYSQL_DATABASE = os.environ.get('MYSQL_DATABASE')
        if MYSQL_SERVER and MYSQL_USERNAME and \
                MYSQL_PASSWORD and MYSQL_DATABASE:
            database_uri = 'mysql+pymysql://' \
                           + MYSQL_USERNAME + ':' \
                           + MYSQL_PASSWORD + '@' \
                           + MYSQL_SERVER + '/' \
                           + MYSQL_DATABASE
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
    MAX_QUEUE_LENGTH = int(os.environ.get('MAX_QUEUE_LENGTH') or 240)
    TIME_TO_DELETE = os.environ.get('TIME_TO_DELETE') or '2 hours'

    ADMIN_FLOCK_ID = os.environ.get('ADMIN_FLOCK_ID')
    ADMIN_FLOCK_TOKEN = os.environ.get('ADMIN_FLOCK_TOKEN')
    TEST_CHANNEL_ID = os.environ.get('TEST_CHANNEL_ID')
    SME_CHANNEL_ID = os.environ.get('SME_CHANNEL_ID')
    BOT_ID = os.environ.get('BOT_ID')
    BOT_TOKEN = os.environ.get('BOT_TOKEN')
