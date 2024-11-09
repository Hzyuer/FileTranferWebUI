# 使用带有 Apache 的 PHP 镜像
FROM php:8.1-apache

# 设置工作目录为 Apache 默认目录
WORKDIR /var/www/html

# 将当前目录的所有内容复制到 /var/www/html/，即 Apache 的默认目录
COPY . /var/www/html/

# 可选：设置文件夹权限
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# 启用 Apache 的 mod_rewrite 模块
RUN a2enmod rewrite