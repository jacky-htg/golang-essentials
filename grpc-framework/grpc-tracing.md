# Tracing

* Tracing penting untuk mencatat setiao request yang masuk.
* Gunakan opentracing agar lebih fleksible.
* Tambahkan env untuk tracing di file .env

```text
PORT = 7070
POSTGRES_HOST = localhost
POSTGRES_PORT = 5432
POSTGRES_USER = postgres
POSTGRES_PASSWORD = pass
POSTGRES_DB = drivers
AUTH_SERVICE = localhost:5050
SERVICE_NAME = skeleton
DD_AGENT_HOST = localhost
```

* Update file server.go untuk memasang opentracing

```go
t := opentracer.New(
        tracer.WithServiceName(os.Getenv("SERVICE_NAME")),
        tracer.WithAnalytics(true),
        tracer.WithAgentAddr(os.Getenv("DD_AGENT_HOST")),
    )
    opentracing.SetGlobalTracer(t)
    defer tracer.Stop()
```

