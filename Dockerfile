FROM php:7.4-apache
COPY . /var/www/html/

RUN echo '#!/bin/bash\n\
while true; do\n\
  cd /var/www/html/ && php log.php > ~/logs.log\n\
done' > /usr/local/bin/run-endlessly.sh

RUN echo '#!/bin/bash\n\
/usr/local/bin/run-endlessly.sh &\n\
exec apache2-foreground\n\' > /usr/local/bin/startup.sh

# Make the script executable
RUN chmod +x /usr/local/bin/run-endlessly.sh /usr/local/bin/startup.sh

# Start the endless loop script as a background process
CMD /usr/local/bin/run-endlessly.sh &
CMD startup.sh

