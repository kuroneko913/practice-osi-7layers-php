.PHONY: build up down clean sender receiver logs

build:
	docker compose build

up:
	docker-compose up -d

down:
	docker-compose down

sender:
	docker-compose run --rm -T sender 

receiver:
	docker-compose run --rm receiver

clean:
	docker compose down -v --remove-orphans
	docker system prune -f

logs:
	docker-compose logs -f

help:
	@echo "make build   # Dockerイメージをビルド"
	@echo "make up      # サーバを起動"
	@echo "make down    # サーバを停止"
	@echo "make sender  # senderを起動する"
	@echo "make receiver # receiverを起動する"
	@echo "make clean   # イメージを削除"
	@echo "make logs    # ログを表示"
	@echo "make help    # このヘルプを表示"
