FROM php:8.2-apache

ติดตั้งส่วนเสริม PostgreSQL สำหรับ PHP
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

คัดลอกไฟล์โปรเจกต์ทั้งหมดไปไว้ในเซิร์ฟเวอร์ Apache
COPY . /var/www/html/

เปิดสิทธิ์การใช้งาน
RUN chown -R www-data:www-data /var/www/html
