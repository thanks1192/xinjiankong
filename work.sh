#!/usr/bin/env bash
if [ ! -f "./vendor/autoload.php" ]; then  
  composer install
fi
docker-compose-wait \
&& nice -n 10 php work.php start -d\
&& nice -n 10 php server.php -e=.env.docker -s=*