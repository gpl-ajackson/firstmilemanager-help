# Forge's deploy script for help.firstmilemanager.co.uk (#33, #58). See docs/deploying.md.
#
# Forge doesn't read this file. It is pasted into the site's deploy script box in Forge
# (Site › Deployments › Deploy script), and this copy is the one to change: when it changes,
# paste the whole file into Forge again before the change is merged, so the next deploy runs it.
#
# Forge replaces its two release macros wherever their names appear, comments included, so they
# are named here without their dollar sign and brackets: never write them out in a comment.
#
# Forge runs it with zero-downtime deployments: the create-release macro clones the repository
# into a new release folder (sharing storage/ and .env with the live one), and only the
# activate-release macro switches readers to it. Any failed step stops the script before then, so the old release keeps
# serving, and Forge emails AJ. The .github/workflows/deploy.yml workflow starts it through the
# deploy hook once every check passed, naming the commit (FORGE_DEPLOY_COMMIT).

$CREATE_RELEASE()

set -e
cd $FORGE_RELEASE_DIRECTORY

# Deploy exactly the commit whose checks passed, even if main has moved on since.
if [ -n "$FORGE_DEPLOY_COMMIT" ]; then
    if [ "$(git rev-parse HEAD)" != "$FORGE_DEPLOY_COMMIT" ]; then
        git fetch --quiet --depth=1 origin "$FORGE_DEPLOY_COMMIT"
        git checkout --quiet --detach "$FORGE_DEPLOY_COMMIT"
    fi
    echo "Deploying $(git rev-parse HEAD)"
fi

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
# The diagram tool (Mermaid in Chromium through Playwright). Chromium itself is installed once
# for the forge user, at the Playwright version tools/diagrams/package.json pins.
npm ci --prefix tools/diagrams --no-audit --no-fund

# Config, routes, views and the files' fingerprints (help:cache-assets), worked out before the
# database is touched, so a mistake here stops the deploy while the live site is untouched.
$FORGE_PHP artisan optimize

$FORGE_PHP artisan migrate --force
# The content rebuild: the database copy of every Article, Glossary Entry and Release Note,
# rendered pages, Pictures and diagrams, and the search index with its synonyms, all or nothing.
$FORGE_PHP artisan help:rebuild

$ACTIVATE_RELEASE()
