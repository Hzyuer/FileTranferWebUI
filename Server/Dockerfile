# 使用 Python 基础镜像
FROM python:3.10.15-slim

# 设置工作目录
WORKDIR /app

# 复制 requirements.txt 并安装依赖
COPY requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

# 复制 server 文件夹中的所有内容到容器中的 /app 目录
COPY ./server /app

# 暴露服务器端口12345
EXPOSE 12345

# 启动 Python 服务器（假设入口文件是 server.py）
CMD ["python", "server.py"]
