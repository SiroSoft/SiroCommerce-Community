#!/bin/sh
# Install a GitHub Actions self-hosted runner for a PRIVATE/INTERNAL repo.
#
# Required:
#   RUNNER_TOKEN  registration token from GitHub (short-lived)
#   GITHUB_ORG    organisation, default SiroSoft
#   GITHUB_REPO   private repo name, default SiroCommerce
#   RUNNER_VERSION e.g. 2.328.0
#
# Optional:
#   RUNNER_NAME   default siro-ci-01
#   RUNNER_LABELS default self-hosted,linux,x64,siro-ci
#   RUNNER_DIR    default /opt/actions-runner
#
# Do NOT use this for the public SiroCommerce-Community repository. Public PRs
# must never execute on a self-hosted runner.
set -eu

ORG="${GITHUB_ORG:-SiroSoft}"
REPO="${GITHUB_REPO:?GITHUB_REPO is required (private repo)}"
TOKEN="${RUNNER_TOKEN:?RUNNER_TOKEN is required}"
VERSION="${RUNNER_VERSION:?RUNNER_VERSION is required}"
NAME="${RUNNER_NAME:-siro-ci-01}"
LABELS="${RUNNER_LABELS:-self-hosted,linux,x64,siro-ci}"
DIR="${RUNNER_DIR:-/opt/actions-runner}"
URL="https://github.com/$ORG/$REPO"

[ "$(id -u)" = "0" ] || { echo "run as root" >&2; exit 1; }
[ "$REPO" != "SiroCommerce-Community" ] || {
  echo "refusing: do not attach a self-hosted runner to the public Community repo" >&2
  exit 1
}

if ! id -u runner >/dev/null 2>&1; then
  useradd --system --create-home --shell /usr/sbin/nologin runner
fi

install -d -o runner -g runner -m 0755 "$DIR"
cd "$DIR"

if [ ! -f "actions-runner-linux-x64-$VERSION.tar.gz" ]; then
  curl -fL -o "actions-runner-linux-x64-$VERSION.tar.gz" \
    "https://github.com/actions/runner/releases/download/v$VERSION/actions-runner-linux-x64-$VERSION.tar.gz"
fi
tar xzf "actions-runner-linux-x64-$VERSION.tar.gz"
chown -R runner:runner "$DIR"

if [ ! -f .runner ]; then
  sudo -u runner ./config.sh \
    --url "$URL" \
    --token "$TOKEN" \
    --name "$NAME" \
    --labels "$LABELS" \
    --unattended \
    --replace
fi

./svc.sh install runner
./svc.sh start
./svc.sh status

echo "runner installed for $URL with labels $LABELS"
echo "next: restrict the runner group to private repositories only"
