# Role Based Access Controller
Untuk memanajemen pengaturan hak akses

## Design Tabel
- Design rbac database pada schema/migrate.go dan schema/seed.go
```
package schema

import (
	"database/sql"

	"github.com/GuiaBolso/darwin"
)

var migrations = []darwin.Migration{
	{
		Version:     1,
		Description: "Add users",
		Script: `
CREATE TABLE users (
	id   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	username         CHAR(15) NOT NULL UNIQUE,
	password         varchar(255) NOT NULL,
	email     VARCHAR(255) NOT NULL UNIQUE,
	is_active TINYINT(1) NOT NULL DEFAULT '0',
	created TIMESTAMP NOT NULL DEFAULT NOW(),
	updated TIMESTAMP NOT NULL DEFAULT NOW(),
	PRIMARY KEY (id)
);`,
	},
	{
		Version:     2,
		Description: "Add access",
		Script: `
CREATE TABLE access (
	id   INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	parent_id         INT(10) UNSIGNED,
	name         varchar(255) NOT NULL UNIQUE,
	alias         varchar(255) NOT NULL UNIQUE,
	created TIMESTAMP NOT NULL DEFAULT NOW(),
	PRIMARY KEY (id)
);`,
	},
	{
		Version:     3,
		Description: "Add roles",
		Script: `
CREATE TABLE roles (
	id   INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	name         varchar(255) NOT NULL UNIQUE,
	created TIMESTAMP NOT NULL DEFAULT NOW(),
	PRIMARY KEY (id)
);`,
	},
	{
		Version:     4,
		Description: "Add access_roles",
		Script: `
CREATE TABLE access_roles (
	id   INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	access_id         INT(10) UNSIGNED NOT NULL,
	role_id         INT(10) UNSIGNED NOT NULL,
	created TIMESTAMP NOT NULL DEFAULT NOW(),
	PRIMARY KEY (id),
	UNIQUE KEY access_roles_unique (access_id, role_id),
	KEY access_roles_access_id (access_id),
	KEY access_roles_role_id (role_id),
	CONSTRAINT fk_access_roles_to_access FOREIGN KEY (access_id) REFERENCES access(id) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT fk_access_roles_to_roles FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE
);`,
	},
	{
		Version:     5,
		Description: "Add roles_users",
		Script: `
CREATE TABLE roles_users (
	id   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
	role_id         INT(10) UNSIGNED NOT NULL,
	user_id         BIGINT(20) UNSIGNED NOT NULL,
	created TIMESTAMP NOT NULL DEFAULT NOW(),
	PRIMARY KEY (id),
	UNIQUE KEY roles_users_unique (role_id, user_id),
	KEY roles_users_role_id (role_id),
	KEY roles_users_user_id (user_id),
	CONSTRAINT fk_roles_users_to_roles FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE,
	CONSTRAINT fk_roles_users_to_users FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
);`,
	},
}

// Migrate attempts to bring the schema for db up to date with the migrations
// defined in this package.
func Migrate(db *sql.DB) error {
	driver := darwin.NewGenericDriver(db, darwin.MySQLDialect{})

	d := darwin.New(driver, migrations, nil)

	return d.Migrate()
}
```

```
package schema

import (
	"database/sql"
	"fmt"
)

// seeds is a string constant containing all of the queries needed to get the
// db seeded to a useful state for development.
//
// Using a constant in a .go file is an easy way to ensure the queries are part
// of the compiled executable and avoids pathing issues with the working
// directory. It has the downside that it lacks syntax highlighting and may be
// harder to read for some cases compared to using .sql files. You may also
// consider a combined approach using a tool like packr or go-bindata.
//
// Note that database servers besides PostgreSQL may not support running
// multiple queries as part of the same execution so this single large constant
// may need to be broken up.

const seedUsers string = `
INSERT INTO users (id, username, password, email, is_active) VALUES
	(1, 'jackyhtg', '$2y$10$ekouPwVdtMEy5AFbogzfSeRloxHzUwEAsM7SyNJXnso/F9ds/XUYy', 'admin@admin.com', 1);
`

const seedAccess string = `
INSERT INTO access (id, name, alias, created) VALUES (1, 'root', 'root', NOW());
`

const seedRoles string = `
INSERT INTO roles (id, name, created) VALUES (1, 'superadmin', NOW());
`

const seedAccessRoles string = `
INSERT INTO access_roles (access_id, role_id) VALUES (1, 1);
`

const seedRolesUsers string = `
INSERT INTO roles_users (role_id, user_id) VALUES (1, 1);
`

// Seed runs the set of seed-data queries against db. The queries are ran in a
// transaction and rolled back if any fail.
func Seed(db *sql.DB) error {
	seeds := []string{
		seedUsers,
		seedAccess,
		seedRoles,
		seedAccessRoles,
		seedRolesUsers,
	}

	tx, err := db.Begin()
	if err != nil {
		return err
	}

	for _, seed := range seeds {
		_, err = tx.Exec(seed)
		if err != nil {
			tx.Rollback()
			fmt.Println("error execute seed")
			return err
		}
	}

	return tx.Commit()
}
```

- go run cmd/main.go migrate && go run cmd/main.go seed untuk dump database

## Design Routing
- Buat routing untuk rbac
```
    // Roles Routing
	{
		roles := controllers.Roles{Db: db, Log: log}
		app.Handle(http.MethodGet, "/roles", roles.List)
		app.Handle(http.MethodGet, "/roles/:id", roles.View)
		app.Handle(http.MethodPost, "/roles", roles.Create)
		app.Handle(http.MethodPut, "/roles/:id", roles.Update)
		app.Handle(http.MethodDelete, "/roles/:id", roles.Delete)
		app.Handle(http.MethodPost, "/roles/:id/access/:access_id", roles.Grant)
		app.Handle(http.MethodDelete, "/roles/:id/access/:access_id", roles.Revoke)
	}

	// Access Routing
	{
		access := controllers.Access{Db: db, Log: log}
		app.Handle(http.MethodGet, "/access", access.List)
	}
```

## Access
- Buat perintah scan-access pada libraries/auth/access.go
```
package auth

import (
	"context"
	"database/sql"
	"essentials/libraries/array"
	"essentials/models"
	"fmt"
	"io/ioutil"
	"strings"
)

var aStr array.ArrString
var aUint32 array.ArrUint32

func ScanAccess(db *sql.DB) error {
	var existingAccess []uint32
	var err error

	// get existing access
	{
		a := models.Access{}
		existingAccess, err = a.GetIDs(context.Background(), db)
	}

	if err != nil {
		return err
	}

	// read routing file
	data, err := ioutil.ReadFile("routing/route.go")
	if err != nil {
		return err
	}

	// set transaction
	tx, err := db.Begin()
	if err != nil {
		return err
	}
	// convert routing to access field
	datas := strings.Split(string(data), "\n")
	for _, env := range datas {
		env = strings.TrimSpace(env)
		if len(env) > 11 && env[:11] == "app.Handle(" {
			routings := strings.Split(env[11:(len(env)-1)], ",")
			httpMethod := routings[0][11:]
			url := strings.TrimSpace(routings[1])
			url = url[1:(len(url) - 1)]
			alias := strings.TrimSpace(routings[2])

			//store access except login route
			isExist, _ := aStr.InArray(url, []string{"/login", "/health"})
			if !isExist {
				urls := strings.Split(url, "/")
				controller := urls[1]
				access := strings.ToUpper(httpMethod) + " " + url
				existingAccess, err = storeAccess(existingAccess, tx, controller, access, alias)
				if err != nil {
					tx.Rollback()
					return err
				}
			}
		}
	}

	// remove existing access
	err = removeAccess(tx, existingAccess)
	if err != nil {
		tx.Rollback()
		return err
	}

	return tx.Commit()
}

func storeAccess(existingAccess []uint32, tx *sql.Tx, controller string, access string, alias string) ([]uint32, error) {
	ctx := context.Background()
	// get or store parent access
	existingAccess, id, err := storeController(existingAccess, ctx, tx, controller)
	if err != nil {
		return existingAccess, err
	}
	nullID := sql.NullInt64{Int64: int64(id), Valid: true}

	u := models.Access{ParentID: nullID, Name: access, Alias: alias}
	err = u.GetByName(ctx, tx)
	if err != sql.ErrNoRows && err != nil {
		return existingAccess, err
	}

	if err == sql.ErrNoRows {
		err = u.Create(ctx, tx)
		if err != nil {
			return existingAccess, err
		}
		println("store " + u.Name)
	} else {
		existingAccess = aUint32.Remove(existingAccess, u.ID)
	}

	return existingAccess, nil
}

func storeController(existingAccess []uint32, ctx context.Context, tx *sql.Tx, controller string) ([]uint32, uint32, error) {
	u := models.Access{Name: controller, Alias: controller}
	err := u.GetByName(ctx, tx)
	if err != sql.ErrNoRows && err != nil {
		return existingAccess, 0, err
	}

	if err == sql.ErrNoRows {
		u.ParentID = sql.NullInt64{Int64: 1, Valid: true}
		err = u.Create(ctx, tx)
		if err != nil {
			return existingAccess, 0, err
		}
		println("store " + u.Name)
	} else {
		existingAccess = aUint32.Remove(existingAccess, u.ID)
	}

	return existingAccess, u.ID, nil
}

func removeAccess(tx *sql.Tx, existingAccess []uint32) error {
	var err error
	ctx := context.Background()

	for _, i := range existingAccess {
		u := models.Access{ID: i}
		err = u.Get(ctx, tx)
		if err != nil {
			return err
		}

		name := u.Name

		err = u.Delete(ctx, tx)
		if err != nil {
			return err
		}

		fmt.Println("Deleted " + name)
	}

	return err
}

```
- Library di atas butuh library array. Buat file libraries/array/string.go dan libraries/array/uint32.go
```
package array

type ArrString string

func (s ArrString) InArray(val string, array []string) (exists bool, index int) {
	exists = false
	index = -1

	for i, s := range array {
		if s == val {
			exists = true
			index = i
			return
		}
	}

	return
}

func (s ArrString) Remove(array []string, value string) []string {
	isExist, index := s.InArray(value, array)
	if isExist {
		array = append(array[:index], array[(index+1):]...)
	}

	return array
}
```

```
package array

type ArrUint32 uint32

func (s ArrUint32) InArray(val uint32, array []uint32) (exists bool, index int) {
	exists = false
	index = -1

	for i, s := range array {
		if s == val {
			exists = true
			index = i
			return
		}
	}

	return
}

func (s ArrUint32) Remove(array []uint32, value uint32) []uint32 {
	isExist, index := s.InArray(value, array)
	if isExist {
		array = append(array[:index], array[(index+1):]...)
	}

	return array
}

func (s ArrUint32) RemoveByIndex(array []uint32, index int) []uint32 {
	return append(array[:index], array[(index+1):]...)
}
```
- Buat model Access models/access.go
```
package models

import (
	"context"
	"database/sql"
	"errors"
	"essentials/libraries/api"
	"essentials/libraries/token"
)

//Access : struct of Access
type Access struct {
	ID       uint32
	ParentID sql.NullInt64
	Name     string
	Alias    string
}

const qAccess = `SELECT id, parent_id, name, alias FROM access`

// List ...
func (u *Access) List(ctx context.Context, tx *sql.Tx) ([]Access, error) {
	list := []Access{}

	rows, err := tx.QueryContext(ctx, qAccess)
	if err != nil {
		return list, err
	}

	defer rows.Close()

	for rows.Next() {
		var access Access
		err = rows.Scan(access.getArgs()...)
		if err != nil {
			return list, err
		}

		list = append(list, access)
	}

	if err := rows.Err(); err != nil {
		return list, err
	}

	if len(list) <= 0 {
		return list, errors.New("Access not found")
	}

	return list, nil
}

//GetByName : get access by name
func (u *Access) GetByName(ctx context.Context, tx *sql.Tx) error {
	return tx.QueryRowContext(ctx, qAccess+" WHERE name=?", u.Name).Scan(u.getArgs()...)
}

//GetByAlias : get access by alias
func (u *Access) GetByAlias(ctx context.Context, tx *sql.Tx) error {
	return tx.QueryRowContext(ctx, qAccess+" WHERE alias=?", u.Alias).Scan(u.getArgs()...)
}

//Get : get access by id
func (u *Access) Get(ctx context.Context, tx *sql.Tx) error {
	return tx.QueryRowContext(ctx, qAccess+" WHERE id=?", u.ID).Scan(u.getArgs()...)
}

//Create new Access
func (u *Access) Create(ctx context.Context, tx *sql.Tx) error {
	const query = `
		INSERT INTO access (parent_id, name, alias, created)
		VALUES (?, ?, ?, NOW())
	`
	stmt, err := tx.PrepareContext(ctx, query)
	if err != nil {
		return err
	}

	res, err := stmt.ExecContext(ctx, u.ParentID, u.Name, u.Alias)
	if err != nil {
		return err
	}

	id, err := res.LastInsertId()
	if err != nil {
		return err
	}

	u.ID = uint32(id)

	return nil
}

//Delete : delete user
func (u *Access) Delete(ctx context.Context, tx *sql.Tx) error {
	stmt, err := tx.PrepareContext(ctx, `DELETE FROM access WHERE id = ?`)
	if err != nil {
		return err
	}

	_, err = stmt.ExecContext(ctx, u.ID)
	return err
}

// GetIDs : get array of access id
func (u *Access) GetIDs(ctx context.Context, db *sql.DB) ([]uint32, error) {
	var access []uint32

	rows, err := db.QueryContext(ctx, "SELECT id FROM access WHERE name != 'root'")
	if err != nil {
		return access, err
	}

	defer rows.Close()

	for rows.Next() {
		var id uint32
		err = rows.Scan(&id)
		if err != nil {
			return access, err
		}
		access = append(access, id)
	}

	return access, rows.Err()
}

// IsAuth for check user authorization
func (u *Access) IsAuth(ctx context.Context, db *sql.DB, tokenparam interface{}, controller string, route string) (bool, error) {
	query := `
	SELECT true
	FROM users
	JOIN roles_users ON users.id = roles_users.user_id
	JOIN roles ON roles_users.role_id = roles.id
	JOIN access_roles ON roles.id = access_roles.role_id
	JOIN access ON access_roles.access_id = access.id
	WHERE (access.name = 'root' OR access.name = ? OR access.name = ?)
	AND users.id = ?
	`
	var isAuth bool
	var err error

	if tokenparam == nil {
		return isAuth, api.ErrBadRequest(errors.New("Bad request for token"), "")
	}

	isValid, username := token.ValidateToken(tokenparam.(string))
	if !isValid {
		return isAuth, api.ErrBadRequest(errors.New("Bad request for invalid token"), "")
	}

	user := User{Username: username}
	err = user.GetByUsername(ctx, db)
	if err != nil {
		return isAuth, err
	}

	err = db.QueryRowContext(ctx, query, controller, route, user.ID).Scan(&isAuth)

	return isAuth, err
}

func (u *Access) getArgs() []interface{} {
	var args []interface{}
	args = append(args, &u.ID)
	args = append(args, &u.ParentID)
	args = append(args, &u.Name)
	args = append(args, &u.Alias)
	return args
}

```

- Ubah file cmd/main.go
```
    switch flag.Arg(0) {
	case "migrate":
		if err := schema.Migrate(db); err != nil {
			return fmt.Errorf("applying migrations: %v", err)
		}
		fmt.Println("Migrations complete")

	case "seed":
		if err := schema.Seed(db); err != nil {
			return fmt.Errorf("seeding database: %v", err)
		}
		fmt.Println("Seed data complete")

	case "scan-access":
		if err := auth.ScanAccess(db); err != nil {
			return fmt.Errorf("scan access : %v", err)
		}
		fmt.Println("Scan access complete")
	}
```
- `go run cmd/main.go scan-access` untuk insert routing ke tabel access

- Buat file controllers/access.go
```
package controllers

import (
	"database/sql"
	"essentials/libraries/api"
	"essentials/models"
	"essentials/payloads/response"
	"fmt"
	"log"
	"net/http"
)

//Access : struct for set Access Dependency Injection
type Access struct {
	Db  *sql.DB
	Log *log.Logger
}

//List : http handler for returning list of access
func (u *Access) List(w http.ResponseWriter, r *http.Request) {
	var access models.Access
	tx, err := u.Db.Begin()
	if err != nil {
		u.Log.Printf("Begin tx : %+v", err)
		api.ResponseError(w, fmt.Errorf("getting access list: %v", err))
		return
	}
	list, err := access.List(r.Context(), tx)
	if err != nil {
		tx.Rollback()
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("getting access list: %v", err))
		return
	}

	var listResponse []*response.AccessResponse
	for _, a := range list {
		var accessResponse response.AccessResponse
		accessResponse.Transform(&a)
		listResponse = append(listResponse, &accessResponse)
	}

	api.ResponseOK(w, listResponse, http.StatusOK)
}
```

- Buat file payloads/response/access_response.go
```
package response

import (
	"essentials/models"
)

//AccessResponse : format json response for user
type AccessResponse struct {
	ID       uint32 `json:"id"`
	ParentID uint32 `json:"parent_id,omitempty"`
	Name     string `json:"name"`
	Alias    string `json:"alias"`
}

//Transform from Access model to Access response
func (u *AccessResponse) Transform(access *models.Access) {
	u.ID = access.ID
	u.ParentID = uint32(access.ParentID.Int64)
	u.Name = access.Name
	u.Alias = access.Alias
}
```

## Roles
- Buat models/role.go
```
package models

import (
	"context"
	"database/sql"
	"errors"
)

// Role : struct of Role
type Role struct {
	ID   uint32
	Name string
}

const qRoles = `SELECT id, name FROM roles`

// List of roles
func (u *Role) List(ctx context.Context, db *sql.DB) ([]Role, error) {
	list := []Role{}

	rows, err := db.QueryContext(ctx, qRoles)
	if err != nil {
		return list, err
	}

	defer rows.Close()

	for rows.Next() {
		var role Role
		err = rows.Scan(role.getArgs()...)
		if err != nil {
			return list, err
		}

		list = append(list, role)
	}

	if err := rows.Err(); err != nil {
		return list, err
	}

	if len(list) <= 0 {
		return list, errors.New("Role not found")
	}

	return list, nil
}

// Get role by id
func (u *Role) Get(ctx context.Context, db *sql.DB) error {
	return db.QueryRowContext(ctx, qRoles+" WHERE id=?", u.ID).Scan(u.getArgs()...)
}

// Create new role
func (u *Role) Create(ctx context.Context, db *sql.DB) error {
	const query = `
		INSERT INTO roles (name, created)
		VALUES (?, NOW())
	`
	stmt, err := db.PrepareContext(ctx, query)
	if err != nil {
		return err
	}

	res, err := stmt.ExecContext(ctx, u.Name)
	if err != nil {
		return err
	}

	id, err := res.LastInsertId()
	if err != nil {
		return err
	}

	u.ID = uint32(id)

	return nil
}

// Update role
func (u *Role) Update(ctx context.Context, db *sql.DB) error {

	stmt, err := db.PrepareContext(ctx, `
		UPDATE roles 
		SET name = ?
		WHERE id = ?
	`)
	if err != nil {
		return err
	}

	_, err = stmt.ExecContext(ctx, u.Name, u.ID)
	return err
}

// Delete role
func (u *Role) Delete(ctx context.Context, db *sql.DB) error {
	stmt, err := db.PrepareContext(ctx, `DELETE FROM roles WHERE id = ?`)
	if err != nil {
		return err
	}

	_, err = stmt.ExecContext(ctx, u.ID)
	return err
}

// Grant access to role
func (u *Role) Grant(ctx context.Context, db *sql.DB, accessID uint32) error {
	stmt, err := db.PrepareContext(ctx, `INSERT INTO access_roles (access_id, role_id) VALUES (?, ?)`)
	if err != nil {
		return err
	}
	_, err = stmt.ExecContext(ctx, accessID, u.ID)
	return err
}

// Revoke access from role
func (u *Role) Revoke(ctx context.Context, db *sql.DB, accessID uint32) error {
	stmt, err := db.PrepareContext(ctx, `DELETE FROM access_roles WHERE access_id= ? AND role_id = ?`)
	if err != nil {
		return err
	}
	_, err = stmt.ExecContext(ctx, accessID, u.ID)
	return err
}

func (u *Role) getArgs() []interface{} {
	var args []interface{}
	args = append(args, &u.ID)
	args = append(args, &u.Name)
	return args
}

```
- Buat file payloads/request/role_request.go
```
package request

import (
	"essentials/models"
)

//NewRoleRequest : format json request for new role
type NewRoleRequest struct {
	Name string `json:"name" validate:"required"`
}

//Transform NewRoleRequest to Role
func (u *NewRoleRequest) Transform() *models.Role {
	var role models.Role
	role.Name = u.Name

	return &role
}

//RoleRequest : format json request for role
type RoleRequest struct {
	ID   uint32 `json:"id,omitempty"  validate:"required"`
	Name string `json:"name,omitempty"  validate:"required"`
}

//Transform RoleRequest to Role
func (u *RoleRequest) Transform(role *models.Role) *models.Role {
	if u.ID == role.ID {
		if len(u.Name) > 0 {
			role.Name = u.Name
		}
	}
	return role
}

```

- Buat file payloads/response/role_response.go
```
package response

import (
	"essentials/models"
)

//RoleResponse : format json response for role
type RoleResponse struct {
	ID   uint32 `json:"id"`
	Name string `json:"name"`
}

//Transform from Role model to Role response
func (u *RoleResponse) Transform(role *models.Role) {
	u.ID = role.ID
	u.Name = role.Name
}
```

- Buat file controllers/roles.go
```
package controllers

import (
	"database/sql"
	"essentials/libraries/api"
	"essentials/models"
	"essentials/payloads/request"
	"essentials/payloads/response"
	"fmt"
	"log"
	"net/http"
	"strconv"

	"github.com/julienschmidt/httprouter"
)

//Roles : struct for set Roles Dependency Injection
type Roles struct {
	Db  *sql.DB
	Log *log.Logger
}

//List : http handler for returning list of roles
func (u *Roles) List(w http.ResponseWriter, r *http.Request) {
	var role models.Role
	list, err := role.List(r.Context(), u.Db)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("getting roles list: %v", err))
		return
	}

	var listResponse []*response.RoleResponse
	for _, role := range list {
		var roleResponse response.RoleResponse
		roleResponse.Transform(&role)
		listResponse = append(listResponse, &roleResponse)
	}

	api.ResponseOK(w, listResponse, http.StatusOK)
}

//View : http handler for retrieve role by id
func (u *Roles) View(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	paramID := ctx.Value("ps").(httprouter.Params).ByName("id")

	id, err := strconv.Atoi(paramID)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("type casting: %v", err))
		return
	}

	var role models.Role
	role.ID = uint32(id)
	err = role.Get(ctx, u.Db)

	if err == sql.ErrNoRows {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, api.ErrNotFound(err, ""))
		return
	}

	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Get Role: %v", err))
		return
	}

	var response response.RoleResponse
	response.Transform(&role)
	api.ResponseOK(w, response, http.StatusOK)
}

//Create : http handler for create new role
func (u *Roles) Create(w http.ResponseWriter, r *http.Request) {
	var roleRequest request.NewRoleRequest
	err := api.Decode(r, &roleRequest, true)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("decode role: %v", err))
		return
	}

	role := roleRequest.Transform()
	err = role.Create(r.Context(), u.Db)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Create Role: %v", err))
		return
	}

	var response response.RoleResponse
	response.Transform(role)
	api.ResponseOK(w, response, http.StatusCreated)
}

//Update : http handler for update role by id
func (u *Roles) Update(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	paramID := ctx.Value("ps").(httprouter.Params).ByName("id")

	id, err := strconv.Atoi(paramID)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("type casting paramID: %v", err))
		return
	}

	var role models.Role
	role.ID = uint32(id)
	err = role.Get(ctx, u.Db)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Get Role: %v", err))
		return
	}

	var roleRequest request.RoleRequest
	err = api.Decode(r, &roleRequest, true)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Decode Role: %v", err))
		return
	}

	if roleRequest.ID <= 0 {
		roleRequest.ID = role.ID
	}
	roleUpdate := roleRequest.Transform(&role)
	err = roleUpdate.Update(ctx, u.Db)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Update role: %v", err))
		return
	}

	var response response.RoleResponse
	response.Transform(roleUpdate)
	api.ResponseOK(w, response, http.StatusOK)
}

// Delete : http handler for delete role by id
func (u *Roles) Delete(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	paramID := ctx.Value("ps").(httprouter.Params).ByName("id")

	id, err := strconv.Atoi(paramID)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("type casting paramID: %v", err))
		return
	}

	var role models.Role
	role.ID = uint32(id)
	err = role.Get(ctx, u.Db)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Get role: %v", err))
		return
	}

	err = role.Delete(ctx, u.Db)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Delete role: %v", err))
		return
	}

	api.ResponseOK(w, nil, http.StatusNoContent)
}

//Grant : http handler for grant access to role
func (u *Roles) Grant(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	ps := ctx.Value("ps").(httprouter.Params)
	paramID := ps.ByName("id")
	paramAccessID := ps.ByName("access_id")

	id, err := strconv.Atoi(paramID)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("type casting paramID: %v", err))
		return
	}

	accessID, err := strconv.Atoi(paramAccessID)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("type casting paramAccessID: %v", err))
		return
	}

	var role models.Role
	role.ID = uint32(id)
	err = role.Get(ctx, u.Db)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Get role: %v", err))
		return
	}

	var access models.Access
	access.ID = uint32(accessID)
	tx, err := u.Db.Begin()
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Begin tx: %v", err))
		return
	}

	err = access.Get(ctx, tx)
	if err != nil {
		tx.Rollback()
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Get access: %v", err))
		return
	}
	tx.Commit()

	err = role.Grant(ctx, u.Db, access.ID)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Grant role: %v", err))
		return
	}

	api.ResponseOK(w, nil, http.StatusOK)
}

//Revoke : http handler for revoke access from role
func (u *Roles) Revoke(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	ps := ctx.Value("ps").(httprouter.Params)
	paramID := ps.ByName("id")
	paramAccessID := ps.ByName("access_id")

	id, err := strconv.Atoi(paramID)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("type casting paramID: %v", err))
		return
	}

	accessID, err := strconv.Atoi(paramAccessID)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("type casting paramAccessID: %v", err))
		return
	}

	var role models.Role
	role.ID = uint32(id)
	err = role.Get(ctx, u.Db)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Get role: %v", err))
		return
	}

	var access models.Access
	access.ID = uint32(accessID)
	tx, err := u.Db.Begin()
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Begin tx: %v", err))
		return
	}

	err = access.Get(ctx, tx)
	if err != nil {
		tx.Rollback()
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Get access: %v", err))
		return
	}
	tx.Commit()

	err = role.Revoke(ctx, u.Db, access.ID)
	if err != nil {
		u.Log.Printf("ERROR : %+v", err)
		api.ResponseError(w, fmt.Errorf("Revoke role: %v", err))
		return
	}

	api.ResponseOK(w, nil, http.StatusNoContent)
}
```

- Update users agar support roles/multi-roles