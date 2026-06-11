#!/usr/bin/env bash
set -euo pipefail

# 运行目录固定为 install.sh 真实所在目录，不依赖用户从哪里执行脚本。
SCRIPT_PATH="${BASH_SOURCE[0]}"
if SCRIPT_REAL_PATH="$(readlink -f -- "$SCRIPT_PATH" 2>/dev/null)"; then
  SCRIPT_PATH="$SCRIPT_REAL_PATH"
fi

DIR="$(cd -P -- "$(dirname -- "$SCRIPT_PATH")" && pwd)"
BASE_DIR="$DIR"
NAME="mpay-receipt-watcher"
IMAGE="mpay-receipt-watcher:licensed"
ARCHIVE="$DIR/image.tar.gz"
ENV_SRC="$DIR/.env"
ENV_EXAMPLE="$DIR/.env.example"

info() {
  printf '[INFO] %s\n' "$*"
}

fail() {
  printf '[ERROR] %s\n' "$*" >&2
  exit 1
}

check_runtime() {
  local kernel cmd
  kernel="$(uname -s 2>/dev/null || true)"
  [ "$kernel" = "Linux" ] || fail "当前脚本面向 Linux Docker 服务器，不支持 Windows/macOS Docker Desktop 作为生产运行环境。"

  for cmd in docker grep sed readlink dirname mkdir chmod; do
    command -v "$cmd" >/dev/null 2>&1 || fail "缺少系统命令：$cmd"
  done
}

docker_cmd() {
  if docker info >/dev/null 2>&1; then
    DOCKER=(docker)
    return
  fi

  if command -v sudo >/dev/null 2>&1 && sudo docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
    return
  fi

  fail "当前用户无法访问 Docker，请用 root 执行，或把当前用户加入 docker 用户组。"
}

env_value() {
  local key="$1"
  local line
  line="$(grep -E "^[[:space:]]*${key}=" "$ENV_SRC" | tail -n 1 || true)"
  line="${line#*=}"
  line="${line#"${line%%[![:space:]]*}"}"
  line="${line%"${line##*[![:space:]]}"}"
  line="${line%\"}"
  line="${line#\"}"
  line="${line%\'}"
  line="${line#\'}"
  printf '%s' "$line"
}

check_files() {
  [ -f "$ARCHIVE" ] || fail "缺少镜像包：$ARCHIVE"
  if [ ! -f "$ENV_SRC" ]; then
    if [ -f "$ENV_EXAMPLE" ]; then
      fail "缺少环境文件：$ENV_SRC。请先执行：cp .env.example .env，然后修改 .env。"
    fi
    fail "缺少环境文件：$ENV_SRC，也没有找到模板：$ENV_EXAMPLE"
  fi
  command -v docker >/dev/null 2>&1 || fail "未找到 docker 命令，请先安装 Docker Engine。"
}

check_env() {
  local keys=(
    REDIS_HOST
    REDIS_PORT
    REDIS_DATABASE
    QUEUE_DATABASE
    RECEIPT_WATCHER_QUEUE
    RECEIPT_WATCHER_DISPATCH_MODE
    RECEIPT_WATCHER_WORKER_PROCESSES
    RECEIPT_WATCHER_TASK_LEASE_SECONDS
    RECEIPT_WATCHER_STREAM_BLOCK_MS
    RECEIPT_WATCHER_POLL_SECONDS
    RECEIPT_WATCHER_ACCOUNT_LIMIT
    RECEIPT_WATCHER_CONCURRENCY
    RECEIPT_WATCHER_IDLE_LOG_SECONDS
    RECEIPT_WATCHER_HEADLESS
    RECEIPT_WATCHER_STORAGE_DIR
    RECEIPT_WATCHER_LICENSE_ENFORCED
    TTSHITU_TYPE_ID
  )

  local key
  for key in "${keys[@]}"; do
    [ -n "$(env_value "$key")" ] || fail "环境文件缺少配置或配置为空：$key"
  done

  [ "$(env_value RECEIPT_WATCHER_STORAGE_DIR)" = "/app/var/storage" ] \
    || fail "RECEIPT_WATCHER_STORAGE_DIR 必须是 /app/var/storage。"
}

prepare_dir() {
  mkdir -p "$BASE_DIR/storage" "$BASE_DIR/logs"
  chmod 700 "$BASE_DIR" "$BASE_DIR/storage" "$BASE_DIR/logs" 2>/dev/null || true
  chmod 600 "$ENV_SRC" 2>/dev/null || true
}

load_image() {
  info "导入镜像：$ARCHIVE"
  local output ref id
  output="$("${DOCKER[@]}" load -i "$ARCHIVE" 2>&1)"
  printf '%s\n' "$output"

  ref="$(printf '%s\n' "$output" | sed -n 's/^Loaded image: //p' | tail -n 1)"
  id="$(printf '%s\n' "$output" | sed -n 's/^Loaded image ID: //p' | tail -n 1)"

  if [ -n "$ref" ] && [ "$ref" != "$IMAGE" ]; then
    "${DOCKER[@]}" tag "$ref" "$IMAGE"
  elif [ -n "$id" ]; then
    "${DOCKER[@]}" tag "$id" "$IMAGE"
  fi

  "${DOCKER[@]}" image inspect "$IMAGE" >/dev/null 2>&1 \
    || fail "镜像导入后未找到 $IMAGE。"
}

run_container() {
  info "创建容器：$NAME"
  "${DOCKER[@]}" rm -f "$NAME" >/dev/null 2>&1 || true
  "${DOCKER[@]}" run -d \
    --name "$NAME" \
    --restart=always \
    --network host \
    --shm-size=1g \
    -v "$BASE_DIR:/app/var" \
    "$IMAGE" >/dev/null

  "${DOCKER[@]}" ps --filter "name=$NAME" --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
}

main() {
  check_runtime
  check_files
  docker_cmd
  check_env
  prepare_dir
  load_image
  run_container

  cat <<EOF

安装完成。

目录：$BASE_DIR
配置：$ENV_SRC
挂载：$BASE_DIR => /app/var
容器：$NAME
镜像：$IMAGE

修改 .env 后生效：
  ${DOCKER[*]} restart $NAME

查看日志：
  ${DOCKER[*]} logs -f $NAME
  tail -f "$BASE_DIR/logs/receipt_watcher.log"
EOF
}

main "$@"
