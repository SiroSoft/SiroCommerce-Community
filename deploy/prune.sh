#!/bin/sh
# Prune dangling Docker images and old SiroCommerce releases/backups.
# Default is dry-run. Pass --apply to actually delete.
set -eu

ROOT="${ROOT:-/opt/sirocommerce}"
RELEASES_DIR="$ROOT/releases"
BACKUPS_DIR="$ROOT/backups"
KEEP_RELEASES="${KEEP_RELEASES:-3}"
KEEP_BACKUPS="${KEEP_BACKUPS:-5}"
APPLY=0

usage() {
  cat <<'EOF'
Usage: deploy/prune.sh [--apply] [--dry-run]

Default is dry-run: prints what would be removed plus disk usage before/after.
--apply actually removes:
  - dangling Docker images (docker image prune -f)
  - release directories outside the newest KEEP_RELEASES (default 3)
  - backup dumps outside the newest KEEP_BACKUPS (default 5)
The current release and the inferred previous release are never removed, even
when they fall outside the keep window.

Environment:
  ROOT=/opt/sirocommerce     host root containing current/, releases/, backups/
  KEEP_RELEASES=3            newest release directories to keep
  KEEP_BACKUPS=5             newest backup dumps to keep
  PREVIOUS_RELEASE=<name>    override previous-release protection
EOF
}

for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --dry-run) APPLY=0 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage >&2; exit 2 ;;
  esac
done

log() { echo "[prune] $*"; }
fail() { echo "[prune] ERROR: $*" >&2; exit 1; }

[ -d "$ROOT" ] || fail "ROOT does not exist: $ROOT"
[ -d "$RELEASES_DIR" ] || fail "releases directory does not exist: $RELEASES_DIR"

release_names() {
  for entry in "$RELEASES_DIR"/*; do
    [ -d "$entry" ] || continue
    basename "$entry"
  done | sort
}

backup_files_sorted() {
  [ -d "$BACKUPS_DIR" ] || return 0
  ls -1t "$BACKUPS_DIR" 2>/dev/null | while IFS= read -r name; do
    case "$name" in
      *.sql|*.sql.gz) printf '%s\n' "$BACKUPS_DIR/$name" ;;
    esac
  done
}

disk_usage() {
  label="$1"
  log "---- disk usage: $label ----"
  df -h "$ROOT" 2>/dev/null || true
  [ -d "$RELEASES_DIR" ] && du -sh "$RELEASES_DIR" 2>/dev/null || true
  [ -d "$BACKUPS_DIR" ] && du -sh "$BACKUPS_DIR" 2>/dev/null || true
  if command -v docker >/dev/null 2>&1; then
    docker system df 2>/dev/null || true
  fi
}

ALL_RELEASES="$(release_names)"
CURRENT_TARGET=""
CURRENT_NAME=""
if [ -e "$ROOT/current" ] || [ -L "$ROOT/current" ]; then
  CURRENT_TARGET="$(readlink -f "$ROOT/current" 2>/dev/null || true)"
fi
if [ -n "${CURRENT_TARGET:-}" ]; then
  CURRENT_NAME="$(basename "$CURRENT_TARGET")"
fi

PREVIOUS_NAME="${PREVIOUS_RELEASE:-}"
if [ -z "$PREVIOUS_NAME" ] && [ -n "$CURRENT_NAME" ]; then
  PREVIOUS_NAME="$(printf '%s\n' "$ALL_RELEASES" | awk -v cur="$CURRENT_NAME" '$0==cur { print prev; exit } { prev=$0 }')"
fi

KEEP_RELEASE_NAMES="$(printf '%s\n' "$ALL_RELEASES" | tail -n "$KEEP_RELEASES")"

remove_release() {
  name="$1"
  case "$name" in
    ""|"."|".."|*/*) fail "unsafe release name: $name" ;;
  esac
  if [ "$APPLY" -eq 1 ]; then
    log "remove release $name"
    rm -rf "$RELEASES_DIR/$name"
  else
    log "DRY-RUN would remove release $name"
  fi
}

disk_usage "before"

log "---- releases ----"
log "current=${CURRENT_NAME:-<none>} previous=${PREVIOUS_NAME:-<none>} keep_newest=$KEEP_RELEASES"
printf '%s\n' "$ALL_RELEASES" | while IFS= read -r name; do
  [ -n "$name" ] || continue
  if [ "$name" = "$CURRENT_NAME" ]; then
    log "keep release $name (current)"
    continue
  fi
  if [ "$name" = "$PREVIOUS_NAME" ]; then
    log "keep release $name (previous)"
    continue
  fi
  if printf '%s\n' "$KEEP_RELEASE_NAMES" | grep -Fqx "$name"; then
    continue
  fi
  remove_release "$name"
done

log "---- backups ----"
if [ -d "$BACKUPS_DIR" ]; then
  index=0
  backup_files_sorted | while IFS= read -r file; do
    index=$((index + 1))
    if [ "$index" -le "$KEEP_BACKUPS" ]; then
      continue
    fi
    if [ "$APPLY" -eq 1 ]; then
      log "remove backup $file"
      rm -f "$file"
    else
      log "DRY-RUN would remove backup $file"
    fi
  done
else
  log "no backups directory at $BACKUPS_DIR"
fi

log "---- docker images ----"
if command -v docker >/dev/null 2>&1; then
  dangling="$(docker images -f dangling=true -q 2>/dev/null | wc -l | tr -d ' ')"
  if [ "$APPLY" -eq 1 ]; then
    log "pruning dangling Docker images (found $dangling)"
    if ! docker image prune -f >/dev/null; then
      log "WARNING: docker image prune failed; continuing"
    fi
  else
    log "DRY-RUN would prune $dangling dangling Docker image(s)"
    docker images -f dangling=true --format '  {{.ID}} {{.Repository}}:{{.Tag}} {{.Size}}' 2>/dev/null || true
  fi
else
  log "docker not found; skipping image prune"
fi

disk_usage "after"

if [ "$APPLY" -eq 1 ]; then
  log "apply complete"
else
  log "dry-run complete; re-run with --apply to delete"
fi
