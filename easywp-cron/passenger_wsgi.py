import os
import sys
dir_path = os.path.dirname(os.path.realpath(__file__))
sys.path.insert(0, dir_path)

from app import app as application
from app import db
from app.models import BotUser, FailedLink, OldVersionFile


@application.shell_context_processor
def make_shell_context():
    """Define objects to use in Flask shell

    Decorators:
        application.shell_context_processor
    """
    return {
        'db': db,
        'FailedLink': FailedLink,
        'BotUser': BotUser,
        'OldVersionFile': OldVersionFile
    }
