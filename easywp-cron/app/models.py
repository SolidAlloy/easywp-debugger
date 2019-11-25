from datetime import datetime

from app import db


class FailedLink(db.Model):
    """Database model to store failed debugger removals.

    Extends:
        db.Model

    Variables:
        __tablename__ {str} -- Overrides the default name of the table
            with the custom one.
        id {object} -- ID of the failed link.
        link {object} -- URL of the debugger file, including the
            website it is located at.
        error {object} -- Short description of the error occurred when
            the app tried deleting the file.
        message {object} -- Long description of the error.
        timestamp {object} -- Timestamp when the failed link was added
            to the database.
    """
    __tablename__ = 'failed_links'
    id = db.Column(db.Integer, primary_key=True)
    link = db.Column(db.String(128), index=True, unique=True)
    error = db.Column(db.String(32), index=True)
    message = db.Column(db.String(64))
    timestamp = db.Column(db.DateTime, index=True, default=datetime.utcnow)

    def __repr__(self):
        return '<Link {}>'.format(self.link)


class BotUser(db.Model):
    """Database model to store information on the users who installed
        the bot.

    Extends:
        db.Model

    Variables:
        __tablename__ {str} -- Overrides the default name of the table
            with the custom one.
        id {object} -- ID of the user in the application's database.
        user_id {object} -- ID of the user provided by Flock.
        token {object} -- User's token for use in Flock.
    """
    __tablename__ = 'bot_users'
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.String(34), index=True, unique=True)
    token = db.Column(db.String(36), index=True, unique=True)

    def __repr__(self):
        return '<BotUser {}>'.format(self.user_id)


class OldVersionFile(db.Model):
    __tablename__ = 'old_version_files'
    id = db.Column(db.Integer, primary_key=True)
    link = db.Column(db.String(128), index=True, unique=True)

    def __repr__(self):
        return '<OldVersionFile {}>'.format(self.link)