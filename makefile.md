# makefile
- Buat file makefile

```
init:
  go mod init skeleton

gen:
	protoc --proto_path=proto --go_out=paths=source_relative,plugins=grpc:./pb proto/*/*.proto

.PHONY: init gen
```

- install [protoc](https://grpc.io/docs/protoc-installation/)
- Uji installasi protoc `protoc --version`
- jalankan perintah berikut:

```
make init
mkdir pb
make gen
```

- jika terjadi error `makefile:2: *** missing separator.  Stop.` Hal itu mungkin disebabkan karena copy paste. Pastikan jarak indentasi merupakan tab, bukan spasi. 