import subprocess
import sys
from functools import wraps

from app import app, cache
from app.functions import job_path_regex
from flask import url_for


class JobManager:
    """
    Manager of the jobs created with help of the "at" linux command.
    It can check length of the queue, find jobs by their content,
        add and delete jobs.
    """

    def log(func, message, *args, **kwargs):
        """Log the response of the function to cron_log.

        Arguments:
            func {object} -- Function object, result of which will be
                logged.
            message {str} -- Message, returned by the function (the
                function return body must be [result, message]).
            args {list} -- Arguments used by the function.
            kwargs {dict} -- Keyword arguments used by the function.
        """
        args_list_string = ', '.join(args)
        if kwargs:
            # This will produce a string like ", arg1=value1, arg2=value2".
            kwargs_list_string = ', ' + ', '.join(
                [key+'='+kwargs[key] for key in kwargs.keys()])
        else:
            kwargs_list_string = ''
        app.job_logger.info('Response in ' + func.__name__ + '('
                            + args_list_string + kwargs_list_string + '): '
                            + message)

    def log_job(func):
        """Call log() each time a function returns
            some message.

        Decorators:
            wraps

        Arguments:
            func {object} -- Function, output of which should be logged.

        Returns:
            object -- Decorated function that will log the output of
                the inner "func" function.
        """
        @wraps(func)
        def decorated_function(*args, **kwargs):
            output = func(*args, **kwargs)
            if output and type(output[0]) is bool:
                JobManager.log(func, output[1], *args, **kwargs)
            else:
                # if find_job() returned an empty list, print the response as
                # "False" in the log.
                JobManager.log(
                    func, ', '.join(output) or 'False', *args, **kwargs)
            return output
        return decorated_function

    def get_queue():
        """Get string, containing a list of jobs.

        Get output from the atq command reflecting the list of job IDs.

        Returns:
            str -- Output of the atq command.

        Raises:
            SystemError -- Raise system error if the atq command is not
                available.
        """
        if sys.version_info.minor >= 7:
            result = subprocess.run('atq', capture_output=True, text=True)  # Output current queue
        else:
            result = subprocess.run('atq', stdout=subprocess.PIPE,
                                    stderr=subprocess.PIPE,
                                    universal_newlines=True)  # Backward compatibility
        if result.returncode != 0:
            raise SystemError('atq is broken.')
        return result.stdout

    @cache.cached(timeout=60, key_prefix='queue_length')
    def get_queue_length():
        """Get number of the jobs in the "atq" queue

        Decorators:
            cache.cached

        Returns:
            int -- Number of jobs in the queue.
        """
        queue = JobManager.get_queue()
        if not queue:
            return 0
        lines = queue.split('\n')[:-1]  # Split output into lines
        return len(lines)

    @log_job
    def find_jobs(domain, path=None):
        """Find a job ID by domain.

        Arguments:
            domain {str} -- Domain to search for.

        Returns:
            mixed -- str if the job ID is returned; False on failure.
        """
        result_numbers = []
        queue = JobManager.get_queue()
        if not queue:
            return []
        lines = queue.split('\n')[:-1]  # Split output into lines
        # Get only the job number for each line
        numbers = [line.split('\t', 1)[0] for line in lines]
        for number in numbers:
            if sys.version_info.minor >= 7:
                output = subprocess.run(['at', '-c', number],
                                        capture_output=True,
                                        text=True).stdout  # Output current queue
            else:
                output = subprocess.run(['at', '-c', number],
                                        stdout=subprocess.PIPE,
                                        stderr=subprocess.PIPE,
                                        universal_newlines=True).stdout  # Backward compatibility
            # Get content of each job until the domain is found
            if path:
                if output.find(domain) != -1 and output.find(path) != -1:
                    result_numbers.append(number)
            else:
                if output.find(domain) != -1:
                    result_numbers.append(number)
        return result_numbers

    @log_job
    def find_path_in_job(job_id):
        if sys.version_info.minor >= 7:
            output = subprocess.run(['at', '-c', str(job_id)],
                                    capture_output=True, text=True).stdout
        else:
            output = subprocess.run(['at', '-c', str(job_id)],
                                    stdout=subprocess.PIPE,
                                    stderr=subprocess.PIPE,
                                    universal_newlines=True).stdout  # Backward compatibility
        result = job_path_regex.search(output)
        if result:
            return [True, result[1]]  # first group of the search result
        else:
            return [False, 'Path was not found in the job #'+job_id+'.']

    @log_job
    def add_job(domain, path):
        """Add a job to the atq queue.

        Add a job that will trigger the /analyze endpoint of the
            application, supplying it with the domain and path to the
            file that should be deleted.

        Arguments:
            domain {str} -- Domain where the debugger file is located.
            path {str} -- Name of the file (including subfolders) to remove.
        """
        try:
            jobs_in_queue = JobManager.find_jobs(domain, path)
        except SystemError:
            return [False, '"atq" doesn\'t work on the server.']

        if jobs_in_queue:
            return [False, 'Job is already created.']
        else:
            if JobManager.get_queue_length() > app.config["MAX_QUEUE_LENGTH"]:  # 240 by default
                return [False, 'Resource is temporarily busy.']
            # The result will be something like 'at now + 2 hours'
            command = ['at', 'now', '+'] + app.config["TIME_TO_DELETE"].split()
            result = subprocess.run(
                command,
                universal_newlines=True,  # instead of text=True for backward compatibility
                input='curl -L -X POST '
                + '-H "Content-Type:application/x-www-form-urlencoded; charset=UTF-8" '
                + '-d "domain=' + domain + '&path=' + path + '" '
                + url_for('analyze', _external=True) +
                ' >/dev/null 2>&1'
            )
            if result.returncode == 0:
                return [True, 'Job successfully created.']
            else:
                return [False, 'Job creation failed.']

    @log_job
    def delete_job(job_id):
        """Delete a job from the queue.

        Arguments:
            job_id {int/str} -- ID of the job to delete.
        """
        result = subprocess.run(['atrm', str(job_id)])
        if result.returncode == 0:
            return [True, 'Job successfully deleted.']
        else:
            return [False, 'There is no such job.']
