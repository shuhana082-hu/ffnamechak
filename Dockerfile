FROM php:8.3-apache

# Apache সেটিংস: .htaccess চালু, ServerName সতর্কতা বন্ধ
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod headers rewrite \
 && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# বিল্ডের সময়ই প্যাকেজ নামবে, vendor আলাদা আপলোড করতে হবে না
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

COPY check.php ./

# cache ফোল্ডার ও সুরক্ষা ফাইল (.htaccess) এখানেই তৈরি হয়
RUN mkdir -p cache \
 && printf 'Require all denied\n' > cache/.htaccess \
 && printf 'Options -Indexes\nRedirectMatch 404 ^/vendor/\nRedirectMatch 404 ^/composer\\.(json|lock)$\nRedirectMatch 404 ^/Dockerfile$\n' > .htaccess \
 && chown -R www-data:www-data cache

# Render যে PORT দেয় Apache সেটাতেই চলবে (ডিফল্ট 10000)
ENV PORT=10000
EXPOSE 10000
CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -i \"s/<VirtualHost \\*:80>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
