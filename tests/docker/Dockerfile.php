# The PHP test image: the floor version this project promises to run on, the
# one extension the test runner needs, and the dev tools from composer.lock.
# At run time the repository is mounted read-only at /repo and nothing in
# here writes to it (see compose.yml).
FROM php:8.1-cli

# pcntl is what lets PHPUnit enforce the per-test time limit. A test that
# hangs then fails and names itself, instead of the whole run being killed
# from outside without a word about where it stopped. unzip and git are for
# Composer, which fetches the dev tools as archives.
RUN apt-get update && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The day tables are keyed on local midnight. Read in another zone, a
# comparison pairs one day with another.
ENV TZ=Europe/Berlin
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# The dev tools live beside the mounted repository rather than inside it: the
# mount is read-only, and vendor/ is not part of the software. Composer's
# autoloader resolves source paths from its own location, so the two symlinks
# are what make it find the sources under /repo.
WORKDIR /opt/build
COPY composer.json composer.lock ./
RUN ln -s /repo/src /opt/build/src \
    && ln -s /repo/tests /opt/build/tests \
    && composer install --no-interaction --no-progress --no-cache

# Not root. A test that only passes as root has found a permission bug and
# reported it as a pass.
RUN useradd --system --uid 1001 --create-home runner
USER runner
WORKDIR /repo
