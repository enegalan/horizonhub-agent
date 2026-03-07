# Horizon Hub Agent

Laravel package that pushes Laravel Horizon events to a Horizon Hub instance and exposes endpoints for job actions (retry, delete, pause/resume queue).

## Requirements

- PHP 8.0+
- Laravel 10, 11, or 12
- Laravel Horizon (necessary; events are only pushed when Horizon is installed)

## Installation

```bash
composer require horizonhub/agent
```

Install (publish config):

```bash
php artisan horizonhub:install
```

Configure `.env`:

```
HORIZON_HUB_URL=https://your-hub.example.com
HORIZON_HUB_API_KEY=your-api-key-from-hub
HORIZON_HUB_SERVICE_NAME=my-service
```

## Events captured

- `JobProcessed`
- `JobFailed`
- `JobProcessing`
- `SupervisorLooped`
- `QueuePaused`
- `QueueResumed`

Events are sent to the Hub as signed HTTP POST requests to `/api/v1/events`.

## Action endpoints

The Hub calls these routes on your application (with signature verification):

- `POST /horizonhub/jobs/{id}/retry` – retry a failed job
- `DELETE /horizonhub/jobs/{id}/delete` – remove a failed job
- `POST /horizonhub/queues/{name}/pause` – pause a queue
- `POST /horizonhub/queues/{name}/resume` – resume a queue

Ensure your application's `base_url` registered in the Hub is reachable by the Hub server.

## License

MIT
