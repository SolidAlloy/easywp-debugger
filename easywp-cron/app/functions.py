import re
from functools import wraps

from app import app
from flask import jsonify

# Regular experessions used for the validation of input.
domain_regex = re.compile(r'^([a-zA-Z0-9][a-zA-Z0-9-_]*\.)*[a-zA-Z0-9]*[a-zA-Z0-9-_]*[a-zA-Z0-9]+$')

# Path must start with slash , then goes an alphanumeric character,
# then it is possible to use almost all kinds of characters like "(" or "_".
# It is necessary that the path doesn't exceed a length of 66 characters
# and ends with .php. Of course, the regex is a bit restrictive as folders
# can start with "_", but it is already pretty general and more freedom
# will make the regex even more prone to injections.
path_regex = re.compile(r'^/[a-zA-Z0-9][a-zA-Z0-9-_()/ ]{0,60}\.php$')

job_path_regex = re.compile(r'path=([.a-zA-Z0-9-_()/ ]+?)"')


def catch_custom_exception(func):
    """Catch exceptions not handled by the app.

    Catch exceptions not handled by the app and pass their traceback to
        the app logger (email, rotating file).

    Decorators:
        wraps

    Arguments:
        func {object} -- Function wrapped by catch_custom_exception()

    Returns:
        mixed -- Value returned by the function or the JSON string if
            an exception is catched.
    """
    @wraps(func)
    def decorated_function(*args, **kwargs):
        try:
            return func(*args, **kwargs)
        except:
            app.error_logger.exception("Exception occurred")
            response = {
                'success': False,
                'message': '500 Internal Server Error'
            }
            return jsonify(response), 500
    return decorated_function


def check_inputs(values_dict):
    """Validate strings against regular expressions.

    Arguments:
        values_dict {dict} -- Dictionary with the
            type_of_input=input_to_validate pairs

    Returns:
        dict -- Dictionary with the
            type_of_input=True_if_input_validated pairs
    """
    result_dict = {}
    for key in values_dict.keys():
        if key == 'domain':
            if values_dict[key] and domain_regex.fullmatch(values_dict[key]):
                result_dict['domain'] = True
            else:
                result_dict['domain'] = False
        if key == 'path':
            if values_dict[key] and path_regex.fullmatch(values_dict[key]):
                result_dict['path'] = True
            else:
                result_dict['path'] = False
    return result_dict


def process_failed_inputs(validated_inputs):
    """Check if all inputs were validated and return corresponding values.

    Arguments:
        validated_inputs {dict} -- Dictionary of the
            type_of_input->input_string pairs.
    """
    # If the domain wasn't validated against the regex
    if not validated_inputs['domain'] and not validated_inputs['path']:
        success = False
        message = 'Domain and path are invalid.'
    elif not validated_inputs['domain']:  # If the domain wasn't validated
        success = False
        message = 'The domain is invalid.'
    else:
        success = False
        message = 'The path is invalid.'
    return [success, message]
