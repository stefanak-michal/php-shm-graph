# php-shm-graph

A graph database engine for PHP backed by System V shared memory (`shmop`/`sysvshm` extension). Built as an experimental project to explore AI-augmented coding workflows — the graph structure, storage layer, and test suite were developed with AI assistance as a vehicle for testing how well AI tools handle low-level PHP extension usage.

## What it does

Stores a labeled property graph (nodes and edges with arbitrary properties) entirely in shared memory segments, with a semaphore-based locking mechanism for concurrent access. No files, no database server.

## Requirements

- PHP 8.1+
- `shmop` extension
- Linux / macOS (System V IPC is not available on Windows)

## Installation

```bash
composer install
```

## Usage

```php
$graph = new \StefanakMichal\PhpShmGraph\Graph('/tmp/my-graph');

$alice = $graph->addNode(['Person'], ['name' => 'Alice', 'age' => 30]);
$bob   = $graph->addNode(['Person'], ['name' => 'Bob',   'age' => 25]);

$edge = $graph->addEdge($alice->id, $bob->id, 'KNOWS');
```

## Running tests

```bash
./vendor/bin/phpunit
```

## Architecture

- **`Graph`** — manages shared memory segments, semaphore locking, ID allocation, and the public API.
- **`Node`** — labeled node with an ID and a property map; stored serialized in a SHM segment.
- **`Edge`** — directed, typed connection between two nodes; also stored in SHM.

Data is spread across fixed-size segments (default 8 MB each). New segments are allocated automatically when existing ones are full.

## How to use it on windows

You can use docker. Create following file `php.Dockerfile`:

```Dockerfile
# Use the version you prefer
FROM php:8.5-cli

# Install the System V IPC extensions (Semaphore, Shared Memory, and Messaging)
RUN docker-php-ext-install sysvsem sysvmsg sysvshm shmop

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Set the working directory
WORKDIR /usr/src/myapp

# Keep the container running
CMD ["tail", "-f", "/dev/null"]
```

Then you have to run following commands to build and run docker container:

```
docker build -f php.Dockerfile -t php-8.5 .
docker run -d --name php-runner -v ".:/usr/src/myapp" php-8.5
docker exec php-runner composer install
```

This way you can use the container to run phpunit tests:

```
docker exec php-runner ./vendor/bin/phpunit --configuration ./phpunit.xml
```
