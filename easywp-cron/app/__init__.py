import logging
import os
import time
from logging.handlers import RotatingFileHandler, SMTPHandler
from os.path import abspath, dirname

from config import Config
from flask import Flask
from flask_caching import Cache
from flask_mail import Mail
from flask_migrate import Migrate
from flask_sqlalchemy import SQLAlchemy
from flask_sslify import SSLify

app = Flask(__name__)
app.config.from_object(Config)
db = SQLAlchemy(app)
migrate = Migrate(app, db)
mail = Mail(app)
cache = Cache(app)
sslify = SSLify(app, permanent=True)


parent_dir = dirname(dirname(abspath(__file__)))
logs_dir = os.path.join(parent_dir, 'logs')
if not os.path.exists(logs_dir):
    os.mkdir(logs_dir)

logging.Formatter.converter = time.gmtime
time_format = "%Y-%m-%d %H:%M:%S %z"

app.error_logger = logging.getLogger('errors')
app.error_logger.setLevel(logging.ERROR)

app.info_logger = logging.getLogger('info')
app.info_logger.setLevel(logging.INFO)

app.job_logger = logging.getLogger('jobs')
app.job_logger.setLevel(logging.INFO)

app.shared_logger = logging.getLogger('shared')
app.shared_logger.setLevel(logging.INFO)

app.vps_logger = logging.getLogger('vps')
app.vps_logger.setLevel(logging.INFO)

if not app.debug:  # Use SMTPHandler only in production
    if app.config['MAIL_SERVER']:
        auth = None
        if app.config['MAIL_USERNAME'] or app.config['MAIL_PASSWORD']:
            auth = (app.config['MAIL_USERNAME'], app.config['MAIL_PASSWORD'])
        secure = None
        if app.config['MAIL_USE_TLS']:
            secure = ()
        mail_handler = SMTPHandler(
            mailhost=(app.config['MAIL_SERVER'], app.config['MAIL_PORT']),
            fromaddr=app.config['MAIL_DEFAULT_SENDER'],
            toaddrs=app.config['MAIL_DEFAULT_RECIPIENT'],
            subject='EasyWP Cron Failure',
            credentials=auth, secure=secure)
        mail_handler.setLevel(logging.ERROR)
        app.error_logger.addHandler(mail_handler)


# Use rotating error log in production and development. It is especially useful in
# an environment like Passenger which streams error log into Apache log.
error_file_handler = RotatingFileHandler(os.path.join(logs_dir, 'error_log'),
                                         maxBytes=10240, backupCount=10)
error_file_handler.setFormatter(logging.Formatter(
    '%(asctime)s %(levelname)s: %(message)s [in %(pathname)s:%(lineno)d]', time_format))
error_file_handler.setLevel(logging.ERROR)
app.error_logger.addHandler(error_file_handler)


# A log file that contains only INFO level messages.
info_file_handler = RotatingFileHandler(os.path.join(logs_dir, 'info_log'),
                                        maxBytes=10240, backupCount=10)
info_file_handler.setFormatter(logging.Formatter(
    '%(asctime)s %(levelname)s: %(message)s [in %(pathname)s:%(lineno)d]', time_format))
info_file_handler.setLevel(logging.INFO)
app.info_logger.addHandler(info_file_handler)


# This log will contain messages about jobs creation, execution, and removal.
job_file_handler = RotatingFileHandler(os.path.join(logs_dir, 'job_log'),
                                       maxBytes=10240, backupCount=10)
job_file_handler.setFormatter(logging.Formatter(
    '%(asctime)s %(levelname)s: %(message)s', time_format))
job_file_handler.setLevel(logging.INFO)
app.job_logger.addHandler(job_file_handler)


shared_file_handler = RotatingFileHandler(os.path.join(logs_dir, 'shared_log'),
                                          maxBytes=10240, backupCount=10)
shared_file_handler.setFormatter(logging.Formatter(
    '%(asctime)s::: %(message)s', time_format))
shared_file_handler.setLevel(logging.INFO)
app.shared_logger.addHandler(shared_file_handler)


vps_file_handler = RotatingFileHandler(os.path.join(logs_dir, 'vps_log'),
                                       maxBytes=10240, backupCount=10)
vps_file_handler.setFormatter(logging.Formatter(
    '%(asctime)s::: %(message)s', time_format))
vps_file_handler.setLevel(logging.INFO)
app.vps_logger.addHandler(vps_file_handler)


from app import models, routes
