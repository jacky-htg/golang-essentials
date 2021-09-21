# Protocol Buffer
- Buat file proto/generic/generic_message.proto

```
syntax = "proto3";
package skeleton;

option go_package = "skeleton/pb/generic;generic";

message EmptyMessage {}
message Id {
  string id = 1;
}

message StringMessage {
  string message = 1;
}

message BoolMessage {
  bool is_true = 1;
}

message Pagination {
  uint32 limit = 1;
  uint32 offset = 2;
  string keyword = 3;
  string order = 4;
  string sort = 5;
}

```

- Buat file proto/drivers/driver_message.proto

```
syntax = "proto3";
package skeleton;

option go_package = "skeleton/pb/drivers;drivers";

message Driver {
  string id = 1;
  string name = 2;
  string phone = 3;
  string licence_number = 4;
  string company_id = 5;
  string company_name = 6;
  bool is_delete = 7;
  string created = 8;
  string created_by = 9;
  string updated = 10;
  string updated_at = 11;
}

message Drivers {
  repeated Driver driver = 1;
}

```

- Buat file proto/drivers/driver_input.proto

```
syntax = "proto3";
package skeleton;

option go_package = "skeleton/pb/drivers;drivers";

import "generic/generic_message.proto";

message DriverListInput {
  repeated string ids = 1;
  repeated string names = 2;
  repeated string phones = 3;
  repeated string licence_numbers = 4;
  repeated string company_ids = 5;
  Pagination pagination = 6;
}

```

- Buat file proto/drivers/driver_service.proto

```
syntax = "proto3";
package skeleton;

option go_package = "skeleton/pb/drivers;drivers";

import "drivers/driver_message.proto";
import "drivers/driver_input.proto";
import "generic/generic_message.proto";

service DriversService {
  rpc List(DriverListInput) returns (Drivers) {}
  rpc Create(Driver) returns (Driver) {}
  rpc Update(Driver) returns (Driver) {}
  rpc Delete(Id) returns (BoolMessage) {}
}
```