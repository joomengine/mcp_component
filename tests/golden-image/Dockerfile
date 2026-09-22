FROM octoleo/joomengine:6.1.6-php8.4-apache

# The installed MCP job runner requires a real, detached PHP CLI worker.
# Keep the native Joomla/JCB image and explicitly provision its CLI prerequisite.
RUN docker-php-ext-install -j2 pcntl \
	&& php -r 'if (!function_exists("pcntl_fork") || !function_exists("posix_setsid") || !function_exists("proc_open")) { throw new RuntimeException("The installed job fixture requires PHP pcntl, POSIX and proc_open."); }'
