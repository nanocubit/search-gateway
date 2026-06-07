.PHONY: install test analyse style fix ci verify clean

install:
	composer install

test:
	vendor/bin/phpunit

analyse:
	vendor/bin/phpstan analyse src tests --level=9 -c phpstan.neon

style:
	vendor/bin/phpcs src tests --standard=phpcs.xml

fix:
	vendor/bin/phpcbf src tests --standard=phpcs.xml

# Standalone smoke-test that needs no composer / vendor / network.
# Runs the autoloaded src/ tree through tools/ci-verify.php.
# Exit code 0 = all pass, 1 = any fail, with explicit SKIP labels for
# components that require ext-redis / Guzzle / Predis.
verify:
	php tools/ci-verify.php

ci: install analyse style test

clean:
	rm -rf vendor .phpunit.cache composer.lock
