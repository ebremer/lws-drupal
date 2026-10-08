# Load test

The listing benchmark of step S5 (DESIGN.md §10): a storage whose container
`big/` holds 10,000 data resources, listed by its controller and by an agent
who may read only half of them.

```sh
# A storage "perf" with big/ and 10,000 members, half text/plain, half
# application/json, and a viewer who may read the containers and the text.
drush php:script tests/load/populate.php
# Access tokens from this site's authorization server, written to files.
drush php:script tests/load/mint.php -- https://id.example/perf /tmp/token-perf.txt
drush php:script tests/load/mint.php -- https://id.example/viewer /tmp/token-viewer.txt
# Timings with ApacheBench: p50, p95 and p99 of each request.
BASE=http://localhost:8899/lws/perf sh tests/load/bench.sh 500
```

Run them as the web server's user, which must be able to read the signing
keys. Measure with the code on a local file system: on a slow mount, such as a
Windows drive in Docker Desktop, the autoloader's file checks dominate.
