from app import app as application
from app import db
from app.models import BotUser, FailedLink


@application.shell_context_processor
def make_shell_context():
    """Define objects to use in Flask shell

    Decorators:
        application.shell_context_processor
    """
    return {
        'db': db,
        'FailedLink': FailedLink,
        'BotUser': BotUser
    }
