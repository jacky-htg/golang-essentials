# Go Guidance

Mempelajari pemrograman golang untuk pemula. Materi akan dibahas step by step dari basic sampai mahir. Ruang lingkup pembelajaran meliputi :

## [Basic](01-golang-fundamental/01-basic.md)

* Install golang
* Hello world
* Package, type, constanta, variable, function
* Flow controll : if, else, switch, for, defer
* Array : array, slice, map

## [Struktur Data](01-golang-fundamental/02-struktur-data.md)

* struct
* Method
* Interface
* Encapsulation, inheritance and polymorphism

## [Konkurensi](01-golang-fundamental/03-konkurensi.md)

* Go routine
* Channel
* Channel dengan buffer
* Range dan close
* Select
* Select default
* Select timeout
* Sync Mutex
* Handling sync group routine

## Design Pattern

* Singleton
* Abstract factory
* Dependency injection
* [Concurrency pattern](02-design-pattern/03-concurrency-pattern.md)
    - [Worker Pool](02-design-pattern/04-worker-pool.md)
    - [Future / Promise](02-design-pattern/08-future-promise.md)
    - [Rate Limit](02-design-pattern/09-rate-limit.md)
    - [Semaphore](02-design-pattern/10-sempahore.md)
    - [Single Flight](02-design-pattern/12-single-flight.md)

## Build API Framework

Step by step membuat golang API framework, baik rest api maunpun grpc, baik monolith maupun microservices, baik monorepo maupun multirepo.

* [Start up](03-build-api-framework/01-start-up.md)
* [Shutdown](03-build-api-framework/02-shutdown.md)
* [Json](03-build-api-framework/03-json.md)
* [Database](03-build-api-framework/04-database.md)
* [Clean architecture](03-build-api-framework/05-clean-architecture.md)
* [Configuration](03-build-api-framework/06-configuration.md)
* [Fatal](03-build-api-framework/07-fatal.md)
* [Bootstrap](03-build-api-framework/08-bootstrap.md)
* [Logging](03-build-api-framework/09-logging.md)
* [Routing](03-build-api-framework/10-routing.md)
* [CRUD](03-build-api-framework/11-crud.md)
* [Standard Response](03-build-api-framework/12-standard-response.md)
* [Error handler](03-build-api-framework/13-error-handler.md)
* [Context](03-build-api-framework/14-context.md)
* [Validation](03-build-api-framework/15-validation.md)
* [Middleware](03-build-api-framework/16-middleware.md)
* [Token](03-build-api-framework/17-token.md)
* [RBAC](03-build-api-framework/18-rbac.md)
* [Pagination](03-build-api-framework/19-pagination.md)
* [Unit testing](03-build-api-framework/20-unit-testing.md)
* [API testing](03-build-api-framework/21-api-testing.md)

## Build gRPC API Framework

* [Protocol Buffer](grpc-framework/grpc-protobuf.md)
* [makefile](grpc-framework/makefile.md)
* [grpc server](grpc-framework/grpc-server.md)
* [Config](grpc-framework/grpc-config.md)
* [Database](grpc-framework/grpc-database.md)
* [Routing](grpc-framework/grpc-routing.md)
* [Clean Architecture](grpc-framework/grpc-clean-architecture.md)
* [gRPC Client](grpc-framework/grpc-client.md)
* [Tracing](grpc-framework/grpc-tracing.md)
* [Caching](grpc-framework/grpc-caching.md)
* [Testing](grpc-framework/grpc-testing.md)

## Referensi Tambahan 

* [Buku "The Go Programing Language"](https://www.gopl.io/)
* [Dokumentasi Resmi Golang](https://golang.org/doc/)