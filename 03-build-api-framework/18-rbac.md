# Bab 18: Role Based Access Controller (Otorisasi)

Setelah memiliki autentikasi (siapa pengguna), langkah selanjutnya adalah otorisasi (apa yang boleh dilakukan pengguna). RBAC adalah pendekatan standar untuk mengelola hak akses berdasarkan peran (role) yang dimiliki pengguna.

## 18.1 Konsep RBAC

```text
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│    Users     │────▶│    Roles     │────▶│   Accesses   │
└──────────────┘     └──────────────┘     └──────────────┘
   (siapa)              (peran)              (apa yang
                                             boleh dilakukan)
```

| Komponen | Deskripsi | Contoh |
|----------|-----------|--------|
| User | Pengguna sistem | admin@example.com |
| Role | Kumpulan akses | superadmin, manager, staff |
| Access | Ijin spesifik | users::create, users::delete |

**Prinsip:** User memiliki Roles, Roles memiliki Accesses. Seorang user bisa memiliki multiple roles. Dam satu role bisa memiliki banyak akses.

## 18.2 Design

### Database

* Gambarkan ERD 

```text
┌─────────┐     ┌────────────┐     ┌─────────┐
│  users  │────▶│ roles_users│◀────│  roles  │
└─────────┘     └────────────┘     └─────────┘
                       │                  │
                       │                  │
                       │           ┌─────────────┐
                       │           │ access_roles│
                       │           └─────────────┘
                       │                  │
                       │                  │
                       │           ┌──────────┐
                       └──────────▶│  access  │
                                   └──────────┘
```

**Cardinality :**

- users : roles → Many-to-Many (via roles_users)
- roles : access → Many-to-Many (via access_roles)
- access : access → One-to-Many (self, untuk hierarki)

### REST API

- GET /access
- GET /roles
- GET /roles/{id}
- POST /roles
- PUT /roles/{id}
- DELETE /roles/{id}
- POST /roles/{id}/access/{access_id}
- DELETE /roles/{id}/access/{access_id}
- GET /users  
- POST /users 
- GET /users/{id}
- PUT /users/{id} 
- DELETE /users/{id}
- POST /login 

### CLI

Menambahkan routing cli `scan-access`, untuk menambahkan accesss setiap kali ada pembuatan routing baru.

### Handler

```go
type AccessHandler interface {
	List(w http.ResponseWriter, r *http.Request)
}

type RoleHandler interface {
	List(w http.ResponseWriter, r *http.Request)
	Create(w http.ResponseWriter, r *http.Request)
	FindByID(w http.ResponseWriter, r *http.Request)
	Update(w http.ResponseWriter, r *http.Request)
	Delete(w http.ResponseWriter, r *http.Request)
	Grant(w http.ResponseWriter, r *http.Request)
	Revoke(w http.ResponseWriter, r *http.Request)
}

type UserHandler interface {
	List(w http.ResponseWriter, r *http.Request)
	Create(w http.ResponseWriter, r *http.Request)
	FindByID(w http.ResponseWriter, r *http.Request)
	Update(w http.ResponseWriter, r *http.Request)
	Delete(w http.ResponseWriter, r *http.Request)
}
```

### Service

```go
type Accesses interface {
	List(ctx context.Context) (map[int]*model.AccessTree, *errors.BusinessError)
	ScanAccess(ctx context.Context) error
}

type Auths interface {
	Login(ctx context.Context, email, password string) (string, *model.User, []string, *errors.BusinessError)
}

type Roles interface {
	List(ctx context.Context) ([]model.Role, *errors.BusinessError)
	FindByID(ctx context.Context, id int) (*model.Role, *errors.BusinessError)
	Create(ctx context.Context, role *model.Role) *errors.BusinessError
	Update(ctx context.Context, role *model.Role) *errors.BusinessError
	Delete(ctx context.Context, id int) *errors.BusinessError
	Grant(ctx context.Context, roleID, accessID int) *errors.BusinessError
	Revoke(ctx context.Context, roleID, accessID int) *errors.BusinessError
}

type Users interface {
	List(ctx context.Context) ([]model.User, *errors.BusinessError)
	Create(ctx context.Context, user *model.User) *errors.BusinessError
	FindByID(ctx context.Context, id string) (*model.User, *errors.BusinessError)
	Update(ctx context.Context, user *model.User) *errors.BusinessError
	Delete(ctx context.Context, id string) *errors.BusinessError
}
```

### Repository

```go
type AccessRepository interface {
	List(ctx context.Context) ([]model.Access, error)
	Create(ctx context.Context, tx *sql.Tx, access *model.Access) error
}

type RoleRepository interface {
	// Basic CRUD
	Create(ctx context.Context, role *model.Role) error
	FindByID(ctx context.Context, id int) (*model.Role, error)
	List(ctx context.Context) ([]model.Role, error)
	Update(ctx context.Context, role *model.Role) error
	Delete(ctx context.Context, id int) error

	// Many-to-many dengan Access
	GrantAccess(ctx context.Context, roleID, accessID int) error
	RevokeAccess(ctx context.Context, roleID, accessID int) error
	GetAccessesByRoles(ctx context.Context, roleIDs []int) ([]model.Access, error)

	// Helper
	HasAccess(ctx context.Context, roleID, accessID int) (bool, error)
}

type UserRepository interface {
	List(ctx context.Context) ([]model.User, error)
	Create(ctx context.Context, tx *sql.Tx, user *model.User) error
	FindByID(ctx context.Context, id string) (*model.User, error)
	FindByEmail(ctx context.Context, email string) (*model.User, error)
	Update(ctx context.Context, tx *sql.Tx, user *model.User) error
	Delete(ctx context.Context, id string) error

	// Manage roles untuk user
	AssignRole(ctx context.Context, tx *sql.Tx, userID string, roleID int64) error
	RemoveRole(ctx context.Context, tx *sql.Tx, userID string, roleID int64) error

	// Check permission
	HasPermission(ctx context.Context, email, routePath, routeGroup string) bool
}
```

### Middleware

Middleware `Auth` diberi kemampuan untuk melakukan otorisasi berdasarkan role-role yang dimiliki oleh user.

## 18.3 Implementasi Database

### ERD

![](../.gitbook/assets/erd.jpg)

### Migration Tabel

Buat file `migration/1_0002_rbac.sql` 

```sql
-- =====================================================
-- TABEL access
-- =====================================================
CREATE TABLE IF NOT EXISTS access (
    id          SERIAL PRIMARY KEY,
    parent_id   INTEGER,
    name        VARCHAR(255) NOT NULL UNIQUE,
    alias       VARCHAR(255) NOT NULL UNIQUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);

-- Index untuk parent_id (hierarchical queries)
CREATE INDEX idx_access_parent_id ON access(parent_id);

-- =====================================================
-- TABEL roles
-- =====================================================
CREATE TABLE IF NOT EXISTS roles (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL UNIQUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);

-- =====================================================
-- TABEL access_roles (many-to-many)
-- =====================================================
CREATE TABLE IF NOT EXISTS access_roles (
    id          SERIAL PRIMARY KEY,
    access_id   INTEGER NOT NULL,
    role_id     INTEGER NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
    
    -- Constraint unique composite
    CONSTRAINT access_roles_unique UNIQUE (access_id, role_id),
    
    -- Foreign keys
    CONSTRAINT fk_access_roles_to_access 
        FOREIGN KEY (access_id) 
        REFERENCES access(id) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    CONSTRAINT fk_access_roles_to_roles 
        FOREIGN KEY (role_id) 
        REFERENCES roles(id) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE
);

-- Index untuk performance
CREATE INDEX idx_access_roles_access_id ON access_roles(access_id);
CREATE INDEX idx_access_roles_role_id ON access_roles(role_id);

-- =====================================================
-- TABEL roles_users (many-to-many)
-- =====================================================
CREATE TABLE IF NOT EXISTS roles_users (
    id          BIGSERIAL PRIMARY KEY,
    role_id     INTEGER NOT NULL,
    user_id     UUID NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
    
    -- Constraint unique composite
    CONSTRAINT roles_users_unique UNIQUE (role_id, user_id),
    
    -- Foreign keys
    CONSTRAINT fk_roles_users_to_roles 
        FOREIGN KEY (role_id) 
        REFERENCES roles(id) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE,
    
    CONSTRAINT fk_roles_users_to_users 
        FOREIGN KEY (user_id) 
        REFERENCES users(id) 
        ON DELETE CASCADE 
        ON UPDATE CASCADE
);

-- Index untuk performance
CREATE INDEX idx_roles_users_role_id ON roles_users(role_id);
CREATE INDEX idx_roles_users_user_id ON roles_users(user_id);

-- =====================================================
-- INDEX TAMBAHAN UNTUK PERFORMANCE (Opsional)
-- =====================================================

-- Index composite untuk query permission checking yang umum
CREATE INDEX idx_access_roles_composite_lookup 
    ON access_roles(role_id, access_id);

-- Index untuk roles_users jika sering join dengan users
CREATE INDEX idx_roles_users_user_role 
    ON roles_users(user_id, role_id);
```

### Seed Data Awal

Buat file migration/3_0002_rbac.sql

```sql
INSERT INTO users (id, name, username, password, email, is_active) VALUES
('019eb960-a27d-73c8-9703-b23a9f50dc83', 'Admin', 'admin', '$2a$10$D7UJmo0/bnXUyvsvRNKmc.cLeiLPNGQ8TfBnQHc2hkQV.oSFBh.qO', 'admin@example.com', true);

INSERT INTO access (id, name, alias) VALUES (1, 'root', 'root');

INSERT INTO roles (id, name) VALUES (1, 'superadmin');

INSERT INTO access_roles (access_id, role_id) VALUES (1, 1);

INSERT INTO roles_users (role_id, user_id) VALUES (1, '019eb960-a27d-73c8-9703-b23a9f50dc83');

```

## 18.4 Implementasi Model

```go
// internal/model/access.go
package model

type Access struct {
	ID       int
	ParentID *int
	Name     string
	Alias    string
}

type AccessTree struct {
	ID        int
	Name      string
	Alias     string
	Childrens []Access
}
```

```go
// internal/model/role.go
package model

type Role struct {
	ID   int
	Name string

	Accesses []Access
}
```

```go
// internal/model/user.go
package model

type User struct {
	ID       string
	Name     string
	Username string
	Password string
	Email    string
	IsActive bool

	Roles []Role
}
```

## 18.5 Implementasi DTO (Reques/Responnse)

### Login Response (diperluas)

```go
// internal/dto/login_response.go
package dto

import "workshop/internal/model"

type LoginResponse struct {
	Token    string       `json:"token"`
	User     UserResponse `json:"user"`
	Accesses []string     `json:"permissions"`
}

func (u *LoginResponse) Transform(token string, user model.User, accesses []string) {
	u.Token = token
	u.Accesses = accesses

	userResp := UserResponse{}
	userResp.Transform(user)
	u.User = userResp
}
```

### Access Response (Tree Structure)

```go
// internal/dto/access_response.go
package dto

import "workshop/internal/model"

type AccessTreeResponse struct {
	ID    int    `json:"id"`
	Name  string `json:"name"`
	Alias string `json:"alias"`

	Childrens []AccessResponse `json:"childrens"`
}

func (u *AccessTreeResponse) Transform(access model.AccessTree) {
	u.ID = access.ID
	u.Name = access.Name
	u.Alias = access.Alias

	for _, val := range access.Childrens {
		var child AccessResponse
		child.Transform(val)
		u.Childrens = append(u.Childrens, child)
	}
}

type AccessResponse struct {
	ID       int    `json:"id"`
	ParentID *int   `json:"parent_id"`
	Name     string `json:"name"`
	Alias    string `json:"alias"`
}

func (u *AccessResponse) Transform(access model.Access) {
	u.ID = access.ID
	u.ParentID = access.ParentID
	u.Name = access.Name
	u.Alias = access.Alias
}
```

### Role Request & Response

```go
// internal/dto/role_request.go
package dto

import "workshop/internal/model"

type RoleRequest struct {
	Name string `json:"name" validate:"required,min=3,max=25"`
}

func (u *RoleRequest) Transform(role *model.Role) {
	role.Name = u.Name
}
```

```go
// internal/dto/role_response.go
package dto

import "workshop/internal/model"

type RoleResponse struct {
	ID       int              `json:"id"`
	Name     string           `json:"name,omitempty"`
	Accesses []AccessResponse `json:"accesses,omitempty"`
}

func (u *RoleResponse) Transform(role model.Role) {
	u.ID = role.ID
	u.Name = role.Name
	u.Accesses = make([]AccessResponse, 0)

	for _, a := range role.Accesses {
		var access AccessResponse
		access.Transform(a)
		u.Accesses = append(u.Accesses, access)
	}
}
```

### Users Request & Response (dengan Roles) 

```go
// internal/dto/user_request.go
package dto

import (
	"workshop/internal/model"
)

type UserRequest struct {
	Name     string `json:"name" validate:"required,min=3,max=100"`
	Username string `json:"username" validate:"required,min=3,max=50"`
	Password string `json:"password" validate:"required,min=10"`
	Email    string `json:"email" validate:"required,email"`
	IsActive bool   `json:"is_active"`

	Roles []int `json:"roles"`
}

func (u *UserRequest) Transform(user *model.User) {
	user.Name = u.Name
	user.Username = u.Username
	user.Password = u.Password
	user.Email = u.Email
	user.IsActive = u.IsActive

	user.Roles = make([]model.Role, 0)

	for _, v := range u.Roles {
		user.Roles = append(user.Roles, model.Role{ID: v})
	}
}

type UserUpdateRequest struct {
	Name     string `json:"name" validate:"required,min=3,max=100"`
	IsActive bool   `json:"is_active"`

	Roles []int `json:"roles"`
}

func (u *UserUpdateRequest) Transform(user *model.User) {
	user.Name = u.Name
	user.IsActive = u.IsActive

	user.Roles = make([]model.Role, 0)

	for _, v := range u.Roles {
		user.Roles = append(user.Roles, model.Role{ID: v})
	}
}
```

```go
// internal/dto/role_response.go
package dto

import "workshop/internal/model"

type UserResponse struct {
	ID       string `json:"id"`
	Name     string `json:"name"`
	Username string `json:"username"`
	Email    string `json:"email"`
	IsActive bool   `json:"is_active"`

	Roles []RoleResponse `json:"roles,omitempty"`
}

func (u *UserResponse) Transform(user model.User) {
	u.ID = user.ID
	u.Name = user.Name
	u.Username = user.Username
	u.Email = user.Email
	u.IsActive = user.IsActive

	u.Roles = make([]RoleResponse, 0)
	for _, r := range user.Roles {
		var role RoleResponse
		role.Transform(r)
		u.Roles = append(u.Roles, role)
	}
}
```

## 18.6 Implementasi Repository

### Access Repository 

```go
// internal/repository/access_repository.go
package repository

import (
	"context"
	"database/sql"
	"fmt"
	"log/slog"
	"workshop/internal/model"

	"github.com/jacky-htg/go-libs/logger"
)

type AccessRepository interface {
	List(ctx context.Context) ([]model.Access, error)
	Create(ctx context.Context, tx *sql.Tx, access *model.Access) error
}

type accessRepository struct {
	db  *sql.DB
	log logger.Logger
}

func NewAccessRepository(db *sql.DB, log logger.Logger) AccessRepository {
	return &accessRepository{db: db, log: log}
}

func (u *accessRepository) List(ctx context.Context) ([]model.Access, error) {
	query := `SELECT id, parent_id, name, alias FROM access WHERE alias != 'root' ORDER BY parent_id, name`
	rows, err := u.db.QueryContext(ctx, query)
	if err != nil {
		u.log.Error(ctx, "error: querying access", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	var list []model.Access = make([]model.Access, 0)
	for rows.Next() {
		var obj model.Access
		if err := rows.Scan(&obj.ID, &obj.ParentID, &obj.Name, &obj.Alias); err != nil {
			u.log.Error(ctx, "error: scanning access row", slog.Any("error", err))
			return nil, err
		}
		list = append(list, obj)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(ctx, "error: iterating access rows", slog.Any("error", err))
		return nil, err
	}

	return list, nil
}

func (u *accessRepository) Create(ctx context.Context, tx *sql.Tx, access *model.Access) error {
	query := `
		WITH inserted AS (
            INSERT INTO access (parent_id, name, alias) 
            VALUES ($1, $2, $3)
            ON CONFLICT (name) DO NOTHING
            RETURNING id
        )
        SELECT id FROM inserted
        UNION ALL
        SELECT id FROM access WHERE name = $2
        LIMIT 1`
	err := tx.QueryRowContext(ctx, query, access.ParentID, access.Name, access.Alias).Scan(&access.ID)
	if err != nil {
		fmt.Println(query, query, *access.ParentID, access.Name, access.Alias)
		u.log.Error(ctx, "error: inserting access", slog.Any("error", err))
		return err
	}

	return nil
}
```

Perhatikan untuk query create, tampak kompleks karena desain operasi insert hanya melalui scan file routing, sehingga jika ada duplikat data akan diabaikan, serta selalu mengembalikan id untuk keperluan mendapatkan id jika access tersebut adalah access parent.

### Role Repository

```go
// internal/repository/role_repository.go
package repository

import (
	"context"
	"database/sql"
	"encoding/json"
	"log/slog"
	"workshop/internal/model"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/lib/pq"
)

type RoleRepository interface {
	// Basic CRUD
	Create(ctx context.Context, role *model.Role) error
	FindByID(ctx context.Context, id int) (*model.Role, error)
	List(ctx context.Context) ([]model.Role, error)
	Update(ctx context.Context, role *model.Role) error
	Delete(ctx context.Context, id int) error

	// Many-to-many dengan Access
	GrantAccess(ctx context.Context, roleID, accessID int) error
	RevokeAccess(ctx context.Context, roleID, accessID int) error
	GetAccessesByRoles(ctx context.Context, roleIDs []int) ([]model.Access, error)

	// Helper
	HasAccess(ctx context.Context, roleID, accessID int) (bool, error)
}

type roleRepository struct {
	db  *sql.DB
	log logger.Logger
}

func NewRoleRepository(db *sql.DB, log logger.Logger) RoleRepository {
	return &roleRepository{db: db, log: log}
}

func (u *roleRepository) Create(ctx context.Context, role *model.Role) error {
	query := `INSERT INTO roles (name) VALUES ($1) RETURNING id`
	err := u.db.QueryRowContext(ctx, query, role.Name).Scan(&role.ID)
	if err != nil {
		u.log.Error(ctx, "error: inserting role", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *roleRepository) FindByID(ctx context.Context, id int) (*model.Role, error) {
	query := `
		SELECT r.id, r.name, 
			    COALESCE(
					json_agg(
						json_build_object(
							'id', a.id,
							'name', a.name,
							'alias', a.alias
						)
					) FILTER (WHERE a.id IS NOT NULL),
					'[]'::json
				)  AS accesses
		FROM roles r
		LEFT JOIN access_roles ar ON (r.id = ar.role_id)
		LEFT JOIN access a ON (ar.access_id = a.id) 
		WHERE r.id = $1 GROUP BY r.id, r.name`

	row := u.db.QueryRowContext(ctx, query, id)

	var obj model.Role
	var accessesJSON []byte
	if err := row.Scan(&obj.ID, &obj.Name, &accessesJSON); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		u.log.Error(ctx, "error: scanning role row", slog.Any("error", err))
		return nil, err
	}

	err := json.Unmarshal(accessesJSON, &obj.Accesses)
	if err != nil {
		u.log.Error(ctx, "error: unmarshall accesses", slog.Any("error", err))
		return nil, err
	}

	return &obj, nil
}

func (u *roleRepository) List(ctx context.Context) ([]model.Role, error) {
	query := `SELECT id, name FROM roles ORDER BY name`
	rows, err := u.db.QueryContext(ctx, query)
	if err != nil {
		u.log.Error(ctx, "error: querying roles", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	var list []model.Role = make([]model.Role, 0)
	for rows.Next() {
		var obj model.Role
		if err := rows.Scan(&obj.ID, &obj.Name); err != nil {
			u.log.Error(ctx, "error: scanning roles row", slog.Any("error", err))
			return nil, err
		}
		list = append(list, obj)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(ctx, "error: iterating roles rows", slog.Any("error", err))
		return nil, err
	}

	return list, nil
}

func (u *roleRepository) Update(ctx context.Context, role *model.Role) error {
	query := `UPDATE roles SET name = $1 WHERE id = $2`
	_, err := u.db.ExecContext(ctx, query, role.Name, role.ID)
	if err != nil {
		u.log.Error(ctx, "error: updating role", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *roleRepository) Delete(ctx context.Context, id int) error {
	query := `DELETE FROM roles WHERE id = $1`
	_, err := u.db.ExecContext(ctx, query, id)
	if err != nil {
		u.log.Error(ctx, "error: delete role", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *roleRepository) GrantAccess(ctx context.Context, roleID, accessID int) error {
	query := `INSERT INTO access_roles (access_id, role_id) VALUES ($1, $2)`
	_, err := u.db.ExecContext(ctx, query, accessID, roleID)
	if err != nil {
		u.log.Error(ctx, "error: grant access", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *roleRepository) RevokeAccess(ctx context.Context, roleID, accessID int) error {
	query := `DELETE FROM access_roles WHERE access_id = $1 AND role_id = $2`
	_, err := u.db.ExecContext(ctx, query, accessID, roleID)
	if err != nil {
		u.log.Error(ctx, "error: grant access", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *roleRepository) GetAccessesByRoles(ctx context.Context, roleIDs []int) ([]model.Access, error) {
	var list []model.Access = make([]model.Access, 0)

	if len(roleIDs) == 0 {
		return list, nil
	}
	query := `
		SELECT DISTINCT a.id, a.parent_id, a.alias 
		FROM roles r
		JOIN access_roles ar ON (r.id = ar.role_id)
		JOIN access a ON (ar.access_id = a.id) 
		WHERE r.id = ANY($1)
		ORDER BY a.parent_id, a.alias`

	rows, err := u.db.QueryContext(ctx, query, pq.Array(roleIDs))
	if err != nil {
		u.log.Error(ctx, "error: querying get access by role", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	for rows.Next() {
		var obj model.Access
		if err := rows.Scan(&obj.ID, &obj.ParentID, &obj.Alias); err != nil {
			u.log.Error(ctx, "error: scanning access row", slog.Any("error", err))
			return nil, err
		}
		list = append(list, obj)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(ctx, "error: iterating access rows", slog.Any("error", err))
		return nil, err
	}

	return list, nil
}

func (u *roleRepository) HasAccess(ctx context.Context, roleID, accessID int) (bool, error) {
	query := `SELECT true FROM access_roles WHERE role_id = $1 AND access_id = $2`
	row := u.db.QueryRowContext(ctx, query, roleID, accessID)

	var hasAccess bool
	if err := row.Scan(&hasAccess); err != nil {
		if err == sql.ErrNoRows {
			return false, nil
		}
		u.log.Error(ctx, "error: scanning role row", slog.Any("error", err))
		return false, err
	}

	return hasAccess, nil
}
```

Pada FindByID query termasuk mendapatkan relasi access

### User Repository (dengan Roles)

```go
// internal/repository/user_repository.go
package repository

import (
	"context"
	"database/sql"
	"encoding/json"
	"log/slog"
	"workshop/internal/model"

	"github.com/jacky-htg/go-libs/logger"
)

type UserRepository interface {
	List(ctx context.Context) ([]model.User, error)
	Create(ctx context.Context, tx *sql.Tx, user *model.User) error
	FindByID(ctx context.Context, id string) (*model.User, error)
	FindByEmail(ctx context.Context, email string) (*model.User, error)
	Update(ctx context.Context, tx *sql.Tx, user *model.User) error
	Delete(ctx context.Context, id string) error

	// Manage roles untuk user
	AssignRole(ctx context.Context, tx *sql.Tx, userID string, roleID int64) error
	RemoveRole(ctx context.Context, tx *sql.Tx, userID string, roleID int64) error

	// Check permission
	HasPermission(ctx context.Context, email, routePath, routeGroup string) bool
}

type userRepository struct {
	db  *sql.DB
	log logger.Logger
}

func NewUserRepository(db *sql.DB, log logger.Logger) UserRepository {
	return &userRepository{db: db, log: log}
}

// List : http handler for returning list of users
func (u *userRepository) List(ctx context.Context) ([]model.User, error) {
	query := `SELECT id, name, username, password, email, is_active FROM users WHERE deleted_at IS NULL`
	rows, err := u.db.QueryContext(ctx, query)
	if err != nil {
		u.log.Error(ctx, "error: querying users", slog.Any("error", err))
		return nil, err
	}
	defer rows.Close()

	var users []model.User = make([]model.User, 0)
	for rows.Next() {
		var user model.User
		if err := rows.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive); err != nil {
			u.log.Error(ctx, "error: scanning user row", slog.Any("error", err))
			return nil, err
		}
		users = append(users, user)
	}

	if err := rows.Err(); err != nil {
		u.log.Error(ctx, "error: iterating user rows", slog.Any("error", err))
		return nil, err
	}

	return users, nil
}

func (u *userRepository) Create(ctx context.Context, tx *sql.Tx, user *model.User) error {
	query := `INSERT INTO users (id, name, username, password, email, is_active) VALUES ($1, $2, $3, $4, $5, $6)`
	_, err := tx.ExecContext(ctx, query, user.ID, user.Name, user.Username, user.Password, user.Email, user.IsActive)
	if err != nil {
		u.log.Error(ctx, "error: inserting user", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) FindByID(ctx context.Context, id string) (*model.User, error) {
	query := `
			SELECT u.id, u.name, u.username, u.password, u.email, u.is_active, 
					COALESCE(
						json_agg(
							json_build_object(
								'id', r.id,
								'name', r.name
							)
						) FILTER (WHERE r.id IS NOT NULL),
						'[]'::json
					)  AS roles
			FROM users u
			LEFT JOIN roles_users ru ON (u.id = ru.user_id)
			LEFT JOIN roles r ON (ru.role_id = r.id) 
			WHERE u.id = $1 AND u.deleted_at IS NULL 
			GROUP BY u.id, u.name, u.username, u.password, u.email, u.is_active`
	row := u.db.QueryRowContext(ctx, query, id)

	var user model.User
	var rolesJSON []byte
	if err := row.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive, &rolesJSON); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		u.log.Error(ctx, "error: scanning user row", slog.Any("error", err))
		return nil, err
	}

	if err := json.Unmarshal(rolesJSON, &user.Roles); err != nil {
		u.log.Error(ctx, "error: unmarshall roles", slog.Any("error", err))
		return nil, err
	}

	return &user, nil
}

func (u *userRepository) FindByEmail(ctx context.Context, email string) (*model.User, error) {
	query := `
			SELECT u.id, u.name, u.username, u.password, u.email, u.is_active, 
					COALESCE(
						json_agg(
							json_build_object(
								'id', r.id,
								'name', r.name
							)
						) FILTER (WHERE r.id IS NOT NULL),
						'[]'::json
					)  AS roles
			FROM users u
			LEFT JOIN roles_users ru ON (u.id = ru.user_id)
			LEFT JOIN roles r ON (ru.role_id = r.id) 
			WHERE u.email = $1 AND u.deleted_at IS NULL 
			GROUP BY u.id, u.name, u.username, u.password, u.email, u.is_active`
	row := u.db.QueryRowContext(ctx, query, email)

	var user model.User
	var rolesJSON []byte
	if err := row.Scan(&user.ID, &user.Name, &user.Username, &user.Password, &user.Email, &user.IsActive, &rolesJSON); err != nil {
		if err == sql.ErrNoRows {
			return nil, nil
		}
		u.log.Error(ctx, "error: scanning user row", slog.Any("error", err))
		return nil, err
	}

	if err := json.Unmarshal(rolesJSON, &user.Roles); err != nil {
		u.log.Error(ctx, "error: unmarshall roles", slog.Any("error", err))
		return nil, err
	}

	return &user, nil
}

func (u *userRepository) Update(ctx context.Context, tx *sql.Tx, user *model.User) error {
	query := `UPDATE users SET name = $1, is_active = $2 WHERE id = $3 RETURNING username, email`
	err := tx.QueryRowContext(ctx, query, user.Name, user.IsActive, user.ID).Scan(&user.Username, &user.Email)
	if err != nil {
		u.log.Error(ctx, "error: updating user", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) Delete(ctx context.Context, id string) error {
	query := `UPDATE users SET deleted_at = timezone('utc', now()) WHERE id = $1`
	_, err := u.db.ExecContext(ctx, query, id)
	if err != nil {
		u.log.Error(ctx, "error: deleting user", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) AssignRole(ctx context.Context, tx *sql.Tx, userID string, roleID int64) error {
	query := `INSERT INTO roles_users (role_id, user_id) VALUES ($1, $2)`
	_, err := tx.ExecContext(ctx, query, roleID, userID)
	if err != nil {
		u.log.Error(ctx, "error: assign role", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) RemoveRole(ctx context.Context, tx *sql.Tx, userID string, roleID int64) error {
	query := `DELETE FROM roles_users WHERE role_id = $1 AND user_id = $2`
	_, err := tx.ExecContext(ctx, query, roleID, userID)
	if err != nil {
		u.log.Error(ctx, "error: remove role", slog.Any("error", err))
		return err
	}

	return nil
}

func (u *userRepository) HasPermission(ctx context.Context, email, routePath, routeGroup string) bool {

	query := `
			SELECT true 
			FROM users u
			JOIN roles_users ru ON (u.id = ru.user_id)
			JOIN roles r ON (ru.role_id = r.id)
			JOIN access_roles ar ON (r.id = ar.role_id) 
			JOIN access a ON (ar.access_id = a.id)
			WHERE u.email = $1 AND (a.name = $2 OR a.name = $3 OR a.name = 'root') `

	var hasPermission bool = false
	err := u.db.QueryRowContext(ctx, query, email, routePath, routeGroup).Scan(&hasPermission)
	if err != nil {
		u.log.Error(ctx, "error: has permission", slog.Any("error", err))
		return false
	}
	return hasPermission
}
```

## 18.7 Implementasi Service

### Access Service

```go
// internal/service/accesses.go
package service

import (
	"context"
	"database/sql"
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"log/slog"
	"strings"
	"workshop/internal/model"
	"workshop/internal/repository"
	"workshop/pkg/app"
	"workshop/pkg/errors"

	"github.com/jacky-htg/go-libs/logger"
)

type Accesses interface {
	List(ctx context.Context) (map[int]*model.AccessTree, *errors.BusinessError)
	ScanAccess(ctx context.Context) error
}

type accesses struct {
	db   *sql.DB
	log  logger.Logger
	repo repository.AccessRepository
}

func NewAccesses(db *sql.DB, log logger.Logger, repo repository.AccessRepository) Accesses {
	return &accesses{db: db, log: log, repo: repo}
}

func (u *accesses) List(ctx context.Context) (map[int]*model.AccessTree, *errors.BusinessError) {
	list, err := u.repo.List(ctx)
	if err != nil {
		return nil, errors.InternalServerErrorWrap(err, "error listing access")
	}

	results := make(map[int]*model.AccessTree)
	for _, val := range list {
		if val.ParentID != nil && *val.ParentID == 1 {
			results[val.ID] = &model.AccessTree{
				ID:        val.ID,
				Name:      val.Name,
				Alias:     val.Alias,
				Childrens: []model.Access{},
			}
		} else if val.ParentID != nil {
			if parent, exists := results[*val.ParentID]; exists {
				parent.Childrens = append(parent.Childrens, val)
			}
		}
	}

	fmt.Printf("result : %v", results)
	return results, nil
}

func (u *accesses) ScanAccess(ctx context.Context) error {
	// Parse route definitions from router file
	routes, err := parseRouteDefinitions("internal/router/api.go")
	if err != nil {
		return fmt.Errorf("failed to parse route definitions: %w", err)
	}

	rootID := 1

	mapGroups := make(map[string]*model.Access)
	for _, route := range routes {
		if _, exists := mapGroups[route.Group]; !exists {
			mapGroups[route.Group] = &model.Access{
				ParentID: &rootID,
				Name:     route.Group,
				Alias:    route.Group,
			}
		}
	}

	tx, err := u.db.BeginTx(ctx, nil)
	if err != nil {
		u.log.Error(ctx, "error begin tx", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err)
	}
	defer tx.Rollback()

	for _, access := range mapGroups {
		err := u.repo.Create(ctx, tx, access)
		if err != nil {
			return err
		}
	}

	list := make([]model.Access, 0)
	for _, route := range routes {
		groupAccess := mapGroups[route.Group]
		if groupAccess == nil {
			return fmt.Errorf("group %s not found for route %s", route.Group, route.Alias)
		}

		list = append(list, model.Access{
			ParentID: &groupAccess.ID,
			Name:     fmt.Sprintf("%s %s", route.Method, route.Path),
			Alias:    route.Alias,
		})
	}

	for _, access := range list {
		err := u.repo.Create(ctx, tx, &access)
		if err != nil {
			return err
		}
	}

	if err = tx.Commit(); err != nil {
		return errors.InternalServerErrorWrap(err)
	}

	return nil
}

func parseRouteDefinitions(filePath string) ([]app.RouteDefinition, error) {
	fset := token.NewFileSet()
	node, err := parser.ParseFile(fset, filePath, nil, parser.ParseComments)
	if err != nil {
		return nil, fmt.Errorf("failed to parse file: %w", err)
	}

	var routes []app.RouteDefinition

	ast.Inspect(node, func(n ast.Node) bool {
		// Look for composite literal
		compLit, ok := n.(*ast.CompositeLit)
		if !ok {
			return true
		}

		// Check if it's a slice
		if compLit.Type == nil {
			return true
		}

		// Try to match array type: []app.RouteDefinition
		if arrayType, ok := compLit.Type.(*ast.ArrayType); ok {
			// Get the element type
			if selectorExpr, ok := arrayType.Elt.(*ast.SelectorExpr); ok {
				// Check if it's app.RouteDefinition
				if ident, ok := selectorExpr.X.(*ast.Ident); ok {
					if ident.Name == "app" && selectorExpr.Sel.Name == "RouteDefinition" {
						// Extract each route from the composite literal
						for _, elt := range compLit.Elts {
							if route, err := parseRouteFromCompositeLit(elt); err == nil {
								routes = append(routes, route)
							}
						}
					}
				}
			}

			// Alternative: check for direct ident (if type is just RouteDefinition)
			if ident, ok := arrayType.Elt.(*ast.Ident); ok {
				if ident.Name == "RouteDefinition" {
					for _, elt := range compLit.Elts {
						if route, err := parseRouteFromCompositeLit(elt); err == nil {
							routes = append(routes, route)
						}
					}
				}
			}
		}

		return true
	})

	if len(routes) == 0 {
		return nil, fmt.Errorf("no route definitions found in %s", filePath)
	}

	return routes, nil
}

func parseRouteFromCompositeLit(expr ast.Expr) (app.RouteDefinition, error) {
	compLit, ok := expr.(*ast.CompositeLit)
	if !ok {
		return app.RouteDefinition{}, fmt.Errorf("not a composite literal")
	}

	route := app.RouteDefinition{}

	for _, elt := range compLit.Elts {
		kv, ok := elt.(*ast.KeyValueExpr)
		if !ok {
			continue
		}

		key, ok := kv.Key.(*ast.Ident)
		if !ok {
			continue
		}

		switch key.Name {
		case "Method":
			if value, ok := kv.Value.(*ast.BasicLit); ok {
				route.Method = strings.Trim(value.Value, `"`)
			}
		case "Path":
			if value, ok := kv.Value.(*ast.BasicLit); ok {
				route.Path = strings.Trim(value.Value, `"`)
			}
		case "Group":
			if value, ok := kv.Value.(*ast.BasicLit); ok {
				route.Group = strings.Trim(value.Value, `"`)
			}
		case "Alias":
			if value, ok := kv.Value.(*ast.BasicLit); ok {
				route.Alias = strings.Trim(value.Value, `"`)
			}
		}
	}

	if route.Method == "" || route.Path == "" || route.Group == "" || route.Alias == "" {
		return app.RouteDefinition{}, fmt.Errorf("incomplete route definition: method=%s, path=%s, group=%s, alias=%s",
			route.Method, route.Path, route.Group, route.Alias)
	}

	return route, nil
}
```

**Catatan:** Fungsi ScanAccess akan dipanggil dari routing cli

### Auths Service (diperluas)

```go
// internal/service/auths.go
package service

import (
	"context"
	"workshop/config"
	"workshop/internal/model"
	"workshop/internal/repository"
	"workshop/pkg/errors"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/token"
	"golang.org/x/crypto/bcrypt"
)

type Auths interface {
	Login(ctx context.Context, email, password string) (string, *model.User, []string, *errors.BusinessError)
}

type auths struct {
	log      logger.Logger
	repo     repository.UserRepository
	roleRepo repository.RoleRepository
	cfgToken config.TokenConfig
}

func NewAuths(log logger.Logger, cfgToken config.TokenConfig, repo repository.UserRepository, roleRepo repository.RoleRepository) Auths {
	return &auths{log: log, repo: repo, roleRepo: roleRepo}
}

func (u *auths) Login(ctx context.Context, email, password string) (string, *model.User, []string, *errors.BusinessError) {
	list := make([]string, 0)

	user, err := u.repo.FindByEmail(ctx, email)
	if err != nil {
		return "", nil, list, errors.InternalServerErrorWrap(err, "error finding user")
	}
	if user == nil {
		return "", nil, list, errors.InvalidInput("Invalid username/password")
	}

	var roleIDs []int = make([]int, 0)
	for _, val := range user.Roles {
		roleIDs = append(roleIDs, val.ID)
	}

	accesses, err := u.roleRepo.GetAccessesByRoles(ctx, roleIDs)
	if err != nil {
		return "", nil, list, errors.InternalServerErrorWrap(err, "error finding user")
	}

	for _, val := range accesses {
		list = append(list, val.Alias)
	}

	err = bcrypt.CompareHashAndPassword([]byte(user.Password), []byte(password))
	if err != nil {
		u.log.Error(ctx, "Invalid username/password")
		return "", nil, list, errors.InvalidInput("Invalid username/password")
	}

	if !user.IsActive {
		return "", nil, list, errors.Forbidden("user inavtive")
	}

	myToken, err := token.ClaimToken(map[string]any{
		"email": user.Email,
		"id":    user.ID,
	}, u.cfgToken.TokenExp)

	if err != nil {
		u.log.Error(ctx, "claim token")
		return "", nil, list, errors.InternalServerErrorWrap(err)
	}

	return myToken, user, list, nil
}
```

### Role Service

```go
// internal/service/roles.go
package service

import (
	"context"
	"workshop/internal/model"
	"workshop/internal/repository"
	"workshop/pkg/errors"

	"github.com/jacky-htg/go-libs/logger"
)

type Roles interface {
	List(ctx context.Context) ([]model.Role, *errors.BusinessError)
	FindByID(ctx context.Context, id int) (*model.Role, *errors.BusinessError)
	Create(ctx context.Context, role *model.Role) *errors.BusinessError
	Update(ctx context.Context, role *model.Role) *errors.BusinessError
	Delete(ctx context.Context, id int) *errors.BusinessError
	Grant(ctx context.Context, roleID, accessID int) *errors.BusinessError
	Revoke(ctx context.Context, roleID, accessID int) *errors.BusinessError
}

type roles struct {
	log  logger.Logger
	repo repository.RoleRepository
}

func NewRoles(log logger.Logger, repo repository.RoleRepository) Roles {
	return &roles{log: log, repo: repo}
}

func (u *roles) List(ctx context.Context) ([]model.Role, *errors.BusinessError) {
	list, err := u.repo.List(ctx)
	if err != nil {
		return nil, errors.InternalServerErrorWrap(err, "error listing roles")
	}
	return list, nil
}

func (u *roles) FindByID(ctx context.Context, id int) (*model.Role, *errors.BusinessError) {
	obj, err := u.repo.FindByID(ctx, id)
	if err != nil {
		return nil, errors.InternalServerErrorWrap(err, "error finding role")
	}
	if obj == nil {
		return nil, errors.NotFound("role not found")
	}
	return obj, nil
}

func (u *roles) Create(ctx context.Context, role *model.Role) *errors.BusinessError {
	if err := u.repo.Create(ctx, role); err != nil {
		return errors.InternalServerErrorWrap(err, "error creating role")
	}

	return nil
}

func (u *roles) Update(ctx context.Context, role *model.Role) *errors.BusinessError {
	existObj, err := u.repo.FindByID(ctx, role.ID)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error finding role")
	}
	if existObj == nil {
		return errors.NotFound("role not found")
	}
	err = u.repo.Update(ctx, role)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error updating role")
	}
	return nil
}

func (u *roles) Delete(ctx context.Context, id int) *errors.BusinessError {
	existObj, err := u.repo.FindByID(ctx, id)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error finding role")
	}
	if existObj == nil {
		return errors.NotFound("role not found")
	}
	err = u.repo.Delete(ctx, id)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error delete role")
	}
	return nil
}

func (u *roles) Grant(ctx context.Context, roleID, accessID int) *errors.BusinessError {
	hasAccess, err := u.repo.HasAccess(ctx, roleID, accessID)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error grant access")
	}

	if !hasAccess {
		err = u.repo.GrantAccess(ctx, roleID, accessID)
		if err != nil {
			return errors.InternalServerErrorWrap(err, "error grant access")
		}
	}
	return nil
}

func (u *roles) Revoke(ctx context.Context, roleID, accessID int) *errors.BusinessError {
	hasAccess, err := u.repo.HasAccess(ctx, roleID, accessID)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error grant access")
	}

	if hasAccess {
		err = u.repo.RevokeAccess(ctx, roleID, accessID)
		if err != nil {
			return errors.InternalServerErrorWrap(err, "error grant access")
		}
	}
	return nil
}
```

### User Service

```go
// internal/service/users.go
package service

import (
	"context"
	"database/sql"
	"log/slog"
	"workshop/internal/model"
	"workshop/internal/repository"
	"workshop/pkg/errors"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/uuid7"
	"golang.org/x/crypto/bcrypt"
)

type Users interface {
	List(ctx context.Context) ([]model.User, *errors.BusinessError)
	Create(ctx context.Context, user *model.User) *errors.BusinessError
	FindByID(ctx context.Context, id string) (*model.User, *errors.BusinessError)
	Update(ctx context.Context, user *model.User) *errors.BusinessError
	Delete(ctx context.Context, id string) *errors.BusinessError
}

type users struct {
	db   *sql.DB
	log  logger.Logger
	repo repository.UserRepository
}

func NewUsers(db *sql.DB, log logger.Logger, repo repository.UserRepository) Users {
	return &users{db: db, log: log, repo: repo}
}

func (u *users) List(ctx context.Context) ([]model.User, *errors.BusinessError) {
	users, err := u.repo.List(ctx)
	if err != nil {
		return nil, errors.InternalServerErrorWrap(err, "error listing users")
	}
	return users, nil
}

func (u *users) Create(ctx context.Context, user *model.User) *errors.BusinessError {
	pass, err := bcrypt.GenerateFromPassword([]byte(user.Password), bcrypt.DefaultCost)
	if err != nil {
		u.log.Error(ctx, "error generate password", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err, "error generating password")
	}

	user.ID = uuid7.New()
	user.Password = string(pass)

	tx, err := u.db.BeginTx(ctx, nil)
	if err != nil {
		u.log.Error(ctx, "error begin tx", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err)
	}
	defer tx.Rollback()

	if err := u.repo.Create(ctx, tx, user); err != nil {
		return errors.InternalServerErrorWrap(err, "error creating user")
	}

	for _, v := range user.Roles {
		if err := u.repo.AssignRole(ctx, tx, user.ID, int64(v.ID)); err != nil {
			return errors.InternalServerErrorWrap(err, "error assign role")
		}
	}

	if err = tx.Commit(); err != nil {
		return errors.InternalServerErrorWrap(err)
	}

	return nil
}

func (u *users) FindByID(ctx context.Context, id string) (*model.User, *errors.BusinessError) {
	user, err := u.repo.FindByID(ctx, id)
	if err != nil {
		return nil, errors.InternalServerErrorWrap(err, "error finding user")
	}
	if user == nil {
		return nil, errors.NotFound("user not found")
	}
	return user, nil
}

func (u *users) Update(ctx context.Context, user *model.User) *errors.BusinessError {
	existUser, err := u.repo.FindByID(ctx, user.ID)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error finding user")
	}
	if existUser == nil {
		return errors.NotFound("user not found")
	}

	tx, err := u.db.BeginTx(ctx, nil)
	if err != nil {
		u.log.Error(ctx, "error begin tx", slog.Any("error", err))
		return errors.InternalServerErrorWrap(err)
	}
	defer tx.Rollback()

	err = u.repo.Update(ctx, tx, user)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error updating user")
	}

	mapExistingRoles := make(map[int]model.Role)
	mapNewRoles := make(map[int]model.Role)

	for _, v := range existUser.Roles {
		mapExistingRoles[v.ID] = v
	}

	for _, w := range user.Roles {
		if _, ok := mapExistingRoles[w.ID]; ok {
			delete(mapExistingRoles, w.ID)
		} else {
			mapNewRoles[w.ID] = w
		}
	}

	for _, val := range mapNewRoles {
		if err := u.repo.AssignRole(ctx, tx, user.ID, int64(val.ID)); err != nil {
			return errors.InternalServerErrorWrap(err, "error update assign role")
		}
	}

	for _, val := range mapExistingRoles {
		if err := u.repo.RemoveRole(ctx, tx, user.ID, int64(val.ID)); err != nil {
			return errors.InternalServerErrorWrap(err, "error update assign role")
		}
	}

	if err = tx.Commit(); err != nil {
		return errors.InternalServerErrorWrap(err)
	}

	return nil
}

func (u *users) Delete(ctx context.Context, id string) *errors.BusinessError {
	existUser, err := u.repo.FindByID(ctx, id)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error finding user")
	}
	if existUser == nil {
		return errors.NotFound("user not found")
	}
	err = u.repo.Delete(ctx, id)
	if err != nil {
		return errors.InternalServerErrorWrap(err, "error deleting user")
	}
	return nil
}
```

## 18.8 Implementasi Handler

### Accesss Handler

```go
// internal/handler/access_handler.go
package handler

import (
	"log/slog"
	"net/http"

	"workshop/internal/dto"
	"workshop/internal/service"
	"workshop/pkg/response"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
)

type AccessHandler interface {
	List(w http.ResponseWriter, r *http.Request)
}

type accessHandler struct {
	log      logger.Logger
	service  service.Accesses
	validate *validator.Validate
}

func NewAccessHandler(log logger.Logger, validate *validator.Validate, service service.Accesses) AccessHandler {
	return &accessHandler{log: log, validate: validate, service: service}
}

func (u *accessHandler) List(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	list, err := u.service.List(ctx)
	if err != nil {
		u.log.Error(ctx, "error: listing access", slog.Any("error", err))
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp []dto.AccessTreeResponse = make([]dto.AccessTreeResponse, 0)
	for _, val := range list {
		var obj dto.AccessTreeResponse
		obj.Transform(*val)
		resp = append(resp, obj)
	}

	response.SetOk(ctx, u.log, w, resp)
}
```

### Auth Handler

```go
// internal/handler/auth_handler.go
package handler

import (
	"encoding/json"
	"log/slog"
	"net/http"

	"workshop/internal/dto"
	"workshop/internal/service"
	"workshop/pkg/errors"
	"workshop/pkg/response"
	"workshop/pkg/validation"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
)

type AuthHandler interface {
	Login(w http.ResponseWriter, r *http.Request)
}

type authHandler struct {
	log      logger.Logger
	service  service.Auths
	validate *validator.Validate
}

func NewAuthHandler(log logger.Logger, validate *validator.Validate, service service.Auths) AuthHandler {
	return &authHandler{log: log, validate: validate, service: service}
}

func (u *authHandler) Login(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var req dto.LoginRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(ctx, "error: decoding login request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), nil)
		return
	}

	if err := u.validate.Struct(req); err != nil {
		u.log.Error(ctx, "error: decoding login request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), validation.FormatValidationErrors(err))
		return
	}

	token, user, accesses, err := u.service.Login(ctx, req.Username, req.Password)
	if err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	resp := dto.LoginResponse{Token: token}
	resp.Transform(token, *user, accesses)
	response.SetOk(ctx, u.log, w, resp)
}
```

### Role Handler

```go
// internal/handler/role_handler.go
package handler

import (
	"encoding/json"
	"log/slog"
	"net/http"
	"strconv"

	"workshop/internal/dto"
	"workshop/internal/model"
	"workshop/internal/service"
	"workshop/pkg/errors"
	"workshop/pkg/response"
	"workshop/pkg/validation"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
)

type RoleHandler interface {
	List(w http.ResponseWriter, r *http.Request)
	Create(w http.ResponseWriter, r *http.Request)
	FindByID(w http.ResponseWriter, r *http.Request)
	Update(w http.ResponseWriter, r *http.Request)
	Delete(w http.ResponseWriter, r *http.Request)
	Grant(w http.ResponseWriter, r *http.Request)
	Revoke(w http.ResponseWriter, r *http.Request)
}

type roleHandler struct {
	log      logger.Logger
	service  service.Roles
	validate *validator.Validate
}

func NewRoleHandler(log logger.Logger, validate *validator.Validate, service service.Roles) RoleHandler {
	return &roleHandler{log: log, validate: validate, service: service}
}

func (u *roleHandler) List(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	list, err := u.service.List(ctx)
	if err != nil {
		u.log.Error(ctx, "error: listing roles", slog.Any("error", err))
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp []dto.RoleResponse = make([]dto.RoleResponse, 0)
	for _, val := range list {
		var obj dto.RoleResponse
		obj.Transform(val)
		resp = append(resp, obj)
	}

	response.SetOk(ctx, u.log, w, resp)
}

func (u *roleHandler) Create(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var req dto.RoleRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(ctx, "error: decoding role request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), nil)
		return
	}

	if err := u.validate.Struct(req); err != nil {
		u.log.Error(ctx, "error: decoding role request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), validation.FormatValidationErrors(err))
		return
	}

	obj := model.Role{}
	req.Transform(&obj)
	err := u.service.Create(ctx, &obj)
	if err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp dto.RoleResponse
	resp.Transform(obj)
	response.SetCreated(ctx, u.log, w, resp)
}

func (u *roleHandler) FindByID(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"), nil)
		return
	}

	roleID, err := strconv.Atoi(id)
	if err != nil {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Invalid id"), nil)
		return
	}

	role, bizErr := u.service.FindByID(ctx, roleID)
	if bizErr != nil {
		response.SetError(ctx, u.log, w, bizErr, nil)
		return
	}

	var resp dto.RoleResponse
	resp.Transform(*role)

	response.SetOk(ctx, u.log, w, resp)
}

func (u *roleHandler) Update(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"), nil)
		return
	}

	roleID, err := strconv.Atoi(id)
	if err != nil {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Invalid id"), nil)
		return
	}

	var req dto.RoleRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), nil)
		return
	}

	if err := u.validate.Struct(req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), validation.FormatValidationErrors(err))
		return
	}

	obj := model.Role{ID: roleID}
	req.Transform(&obj)
	if err := u.service.Update(ctx, &obj); err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp dto.RoleResponse
	resp.Transform(obj)

	response.SetOk(ctx, u.log, w, resp)
}

func (u *roleHandler) Delete(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"), nil)
		return
	}

	roleID, err := strconv.Atoi(id)
	if err != nil {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Invalid id"), nil)
		return
	}

	if err := u.service.Delete(ctx, roleID); err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}
	response.SetOk(ctx, u.log, w, struct{}{})
}

func (u *roleHandler) Grant(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"), nil)
		return
	}

	idAccess := r.PathValue("access_id")
	if idAccess == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing access_id parameter"), nil)
		return
	}

	roleID, err := strconv.Atoi(id)
	if err != nil {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Invalid id"), nil)
		return
	}

	accessID, err := strconv.Atoi(idAccess)
	if err != nil {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Invalid access_id"), nil)
		return
	}

	if err := u.service.Grant(ctx, roleID, accessID); err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}
	response.SetOk(ctx, u.log, w, struct{}{})
}

func (u *roleHandler) Revoke(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"), nil)
		return
	}

	idAccess := r.PathValue("access_id")
	if idAccess == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing access_id parameter"), nil)
		return
	}

	roleID, err := strconv.Atoi(id)
	if err != nil {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Invalid id"), nil)
		return
	}

	accessID, err := strconv.Atoi(idAccess)
	if err != nil {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Invalid access_id"), nil)
		return
	}

	if err := u.service.Revoke(ctx, roleID, accessID); err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}
	response.SetOk(ctx, u.log, w, struct{}{})
}
```

### User Handler

```go
// internal/handler/user_handler.go
package handler

import (
	"encoding/json"
	"log/slog"
	"net/http"

	"workshop/internal/dto"
	"workshop/internal/model"
	"workshop/internal/service"
	"workshop/pkg/errors"
	"workshop/pkg/response"
	"workshop/pkg/validation"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
)

type UserHandler interface {
	List(w http.ResponseWriter, r *http.Request)
	Create(w http.ResponseWriter, r *http.Request)
	FindByID(w http.ResponseWriter, r *http.Request)
	Update(w http.ResponseWriter, r *http.Request)
	Delete(w http.ResponseWriter, r *http.Request)
}

type userHandler struct {
	log      logger.Logger
	service  service.Users
	validate *validator.Validate
}

func NewUserHandler(log logger.Logger, validate *validator.Validate, service service.Users) UserHandler {
	return &userHandler{log: log, validate: validate, service: service}
}

// List : http handler for returning list of users
func (u *userHandler) List(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	users, err := u.service.List(ctx)
	if err != nil {
		u.log.Error(ctx, "error: listing users", slog.Any("error", err))
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp []dto.UserResponse = make([]dto.UserResponse, 0)
	for _, user := range users {
		var ur dto.UserResponse
		ur.Transform(user)
		resp = append(resp, ur)
	}

	response.SetOk(ctx, u.log, w, resp)
}

// Create : http handler for creating a new user
func (u *userHandler) Create(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	var req dto.UserRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), nil)
		return
	}

	if err := u.validate.Struct(req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), validation.FormatValidationErrors(err))
		return
	}

	user := model.User{}
	req.Transform(&user)
	err := u.service.Create(ctx, &user)
	if err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp dto.UserResponse
	resp.Transform(user)
	response.SetCreated(ctx, u.log, w, resp)
}

// FindById : http handler for finding a user by ID
func (u *userHandler) FindByID(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"), nil)
		return
	}

	user, err := u.service.FindByID(ctx, id)
	if err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp dto.UserResponse
	resp.Transform(*user)

	response.SetOk(ctx, u.log, w, resp)
}

func (u *userHandler) Update(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"), nil)
		return
	}

	var req dto.UserUpdateRequest
	if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), nil)
		return
	}

	if err := u.validate.Struct(req); err != nil {
		u.log.Error(ctx, "error: decoding user request", slog.Any("error", err))
		response.SetError(ctx, u.log, w, errors.InvalidInputWrap(err), validation.FormatValidationErrors(err))
		return
	}

	user := model.User{ID: id}
	req.Transform(&user)
	err := u.service.Update(ctx, &user)
	if err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}

	var resp dto.UserResponse
	resp.Transform(user)

	response.SetOk(ctx, u.log, w, resp)
}

func (u *userHandler) Delete(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	id := r.PathValue("id")
	if id == "" {
		response.SetError(ctx, u.log, w, errors.InvalidInput("Missing id parameter"), nil)
		return
	}

	err := u.service.Delete(ctx, id)
	if err != nil {
		response.SetError(ctx, u.log, w, err, nil)
		return
	}
	response.SetOk(ctx, u.log, w, struct{}{})
}
```


## 18.9 Route Definition untuk Scan Access

Karena kita perlu mendaftarkan semua route ke database untuk keperluan otorisasi, buat struct untuk mendefinisikan route:

* untuk routing, saya ada perubahan karena kebutuhan scan access. Problem utama adalah net/http tidak menyimpan path pattern, pattern yang sudha dibuat di routing hanya digunakan untuk match routing kemduian dibuang. Padahal di chi / httprouter informasi tentang pattern ini dikeep. problem kedua, sistem otorisasi saya menggunakn tree, dari root -> group -> path, karena kebutuhan ini saya perlu memnyimpan informasi grouping path. karena itulah saya membuat helper RouteDefinition, dan merombak routing menggunakan RouteDefinision.

```go
// pkg/app/route_definition.go
package app

import "net/http"

type RouteDefinition struct {
	Method      string
	Path        string
	Group       string
	Alias       string
	HandlerFunc http.HandlerFunc
}
```

## 18.10 Router dengan Route Definitions

```go
// internal/router/api.go
package router

import (
	"context"
	"database/sql"
	"fmt"
	"net/http"
	"workshop/config"
	"workshop/internal/handler"
	"workshop/internal/repository"
	"workshop/internal/service"
	"workshop/pkg/app"
	mid "workshop/pkg/middleware"
	"workshop/pkg/response"

	"github.com/go-playground/validator/v10"
	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/middleware"
)

func Api(
	cfg config.Config,
	db *sql.DB,
	log logger.Logger,
	validate *validator.Validate,
) http.Handler {
	mux := http.NewServeMux()

	base := middleware.Stack{
		mid.Recovery(log),
		mid.Timeout(log, cfg.Server.GatewayTimeout),
	}
	private := base.With(mid.Auth(db, log))

	accessRepository := repository.NewAccessRepository(db, log)
	roleRepository := repository.NewRoleRepository(db, log)
	userRepository := repository.NewUserRepository(db, log)

	accessService := service.NewAccesses(db, log, accessRepository)
	authService := service.NewAuths(log, cfg.Token, userRepository, roleRepository)
	roleService := service.NewRoles(log, roleRepository)
	userService := service.NewUsers(db, log, userRepository)

	accessHandler := handler.NewAccessHandler(log, validate, accessService)
	authHandler := handler.NewAuthHandler(log, validate, authService)
	roleHandler := handler.NewRoleHandler(log, validate, roleService)
	userHandler := handler.NewUserHandler(log, validate, userService)

	mux.Handle("GET /health", base.Then(func(w http.ResponseWriter, r *http.Request) {
		response.SetOk(r.Context(), log, w, struct{}{})
	}))

	mux.Handle("POST /login", base.Then(authHandler.Login))

	privateRoutes := []app.RouteDefinition{
		{Method: "GET", Path: "/accesses", Group: "accesses", Alias: "accesses::list", HandlerFunc: accessHandler.List},

		{Method: "GET", Path: "/roles", Group: "roles", Alias: "roles::list", HandlerFunc: roleHandler.List},
		{Method: "POST", Path: "/roles", Group: "roles", Alias: "roles::create", HandlerFunc: roleHandler.Create},
		{Method: "GET", Path: "/roles/{id}", Group: "roles", Alias: "roles::view", HandlerFunc: roleHandler.FindByID},
		{Method: "PUT", Path: "/roles/{id}", Group: "roles", Alias: "roles::update", HandlerFunc: roleHandler.Update},
		{Method: "DELETE", Path: "/roles/{id}", Group: "roles", Alias: "roles::delete", HandlerFunc: roleHandler.Delete},
		{Method: "POST", Path: "/roles/{id}/access/{access_id}", Group: "roles", Alias: "roles::grant", HandlerFunc: roleHandler.Grant},
		{Method: "DELETE", Path: "/roles/{id}/access/{access_id}", Group: "roles", Alias: "roles::revoke", HandlerFunc: roleHandler.Revoke},

		{Method: "GET", Path: "/users", Group: "users", Alias: "users::list", HandlerFunc: userHandler.List},
		{Method: "POST", Path: "/users", Group: "users", Alias: "users::create", HandlerFunc: userHandler.Create},
		{Method: "GET", Path: "/users/{id}", Group: "users", Alias: "users::view", HandlerFunc: userHandler.FindByID},
		{Method: "PUT", Path: "/users/{id}", Group: "users", Alias: "users::update", HandlerFunc: userHandler.Update},
		{Method: "DELETE", Path: "/users/{id}", Group: "users", Alias: "users::delete", HandlerFunc: userHandler.Delete},
	}

	for _, route := range privateRoutes {
		pattern := fmt.Sprintf("%s %s", route.Method, route.Path)
		wrappedHandler := wrapWithRoutePattern(pattern, route.Group, private.Then(route.HandlerFunc))
		mux.Handle(pattern, wrappedHandler)
	}

	return mux
}

func wrapWithRoutePattern(pattern, group string, handler http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		ctx := context.WithValue(r.Context(), app.MyCtx("route-path"), pattern)
		ctx = context.WithValue(ctx, app.MyCtx("route-group"), group)
		handler.ServeHTTP(w, r.WithContext(ctx))
	})
}
```

Perhatikan ada fungsi baru: wrapWithRoutePattern, sebelum masuk ke middleware, kita sisipkan informasi terkait pattern dan group ke dalam context value.

## 18.11 CLI untuk Scan Access

Tambahkan command scan-access untuk mendaftarkan semua route ke database:

```go
// internal/router/cli.go
package router

import (
	"context"
	"database/sql"
	"fmt"
	"workshop/internal/repository"
	"workshop/internal/service"

	"github.com/jacky-htg/go-libs/logger"
	"github.com/jacky-htg/go-libs/migration"
)

func Cli(
	db *sql.DB,
	log logger.Logger,
	command string,
	args []string) error {

	accessRepository := repository.NewAccessRepository(db, log)
	accessService := service.NewAccesses(db, log, accessRepository)

	switch command {
	case "migrate":
		err := migration.Migrate(db, "migration")
		if err != nil {
			log.Error(context.Background(), "Migration failed", "error", err)
			return err
		}
		log.Info(context.Background(), "Migration completed successfully")
	case "scan-access":
		err := accessService.ScanAccess(context.Background())
		if err != nil {
			log.Error(context.Background(), "Scan Access failed", "error", err)
			return err
		}
		log.Info(context.Background(), "Scan access completed successfully")
	default:
		return fmt.Errorf("Error: perintah tidak valid")
	}

	return nil
}
```

**Penggunaan:**

```bash
go run cmd/cli/main.go scan-access
```

## 18.12 Middleware Auth dengan Otorisasi

Middleware sekarang memeriksa apakah user memiliki permission untuk mengakses route:

```go
// pkg/middleware/auth_middleware.go
package middleware

import (
	"context"
	"database/sql"
	"log/slog"
	"net/http"
	"strings"
	"workshop/internal/repository"
	"workshop/pkg/app"
	"workshop/pkg/errors"
	"workshop/pkg/response"

	"github.com/jacky-htg/go-libs/logger"
	lib "github.com/jacky-htg/go-libs/middleware"
	"github.com/jacky-htg/go-libs/token"
)

func Auth(db *sql.DB, log logger.Logger) lib.Middleware {
	return func(next http.Handler) http.Handler {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			ctx := r.Context()
			authHeader := r.Header.Get("Authorization")
			if authHeader == "" {
				err := errors.Unauthorized()
				log.Error(ctx, "Unauthorized", slog.Any("error", err))
				response.SetError(ctx, log, w, err, nil)
				return
			}

			mytoken := strings.TrimPrefix(authHeader, "Bearer ")
			if mytoken == authHeader {
				err := errors.Unauthorized("Invalid authorization header")
				log.Error(ctx, "Unauthorized", slog.Any("error", err))
				response.SetError(ctx, log, w, err, nil)
				return
			}

			isValid, claim := token.ValidateToken(mytoken)
			if !isValid {
				err := errors.Unauthorized("Invalid token")
				log.Error(ctx, "Unauthorized", slog.Any("error", err))
				response.SetError(ctx, log, w, err, nil)
				return
			}

			email := token.GetString(claim, "email")
			repo := repository.NewUserRepository(db, log)
			hasPermission := repo.HasPermission(
				ctx,
				email,
				ctx.Value(app.MyCtx("route-path")).(string),
				ctx.Value(app.MyCtx("route-group")).(string),
			)
			if !hasPermission {
				response.SetError(ctx, log, w, errors.Forbidden(), nil)
				return
			}

			ctx = context.WithValue(ctx, app.MyCtx("email"), email)
			ctx = context.WithValue(ctx, app.MyCtx("userID"), token.GetString(claim, "id"))

			next.ServeHTTP(w, r.WithContext(ctx))
		})
	}
}
```

## 18.13 REST API Endpoints yang Tersedia

| Method | Endpoint | Deskripsi |
|--------|----------|-----------|
| GET | `/accesses` | List semua access (tree structure) |
| GET | `/roles` | List semua role |
| POST | `/roles` | Buat role baru |
| GET | `/roles/{id}` | Detail role (dengan accesses) |
| PUT | `/roles/{id}` | Update role |
| DELETE | `/roles/{id}` | Hapus role |
| POST | `/roles/{id}/access/{access_id}` | Grant access ke role |
| DELETE | `/roles/{id}/access/{access_id}` | Revoke access dari role |
| GET | `/users` | List semua user | 
| POST | `/users` | Buat user baru (dengan roles) |
| GET | `/users/{id}` | Detail user (dengan roles) |
| PUT | `/users/{id}` | Update user (dengan roles)
| DELETE | `/users/{id}` | Hapus user |
| POST | `/login` | Mendapatakn token dan permissions |


## 18.14 Testing RBAC

### Login sebagai Admin

```bash
curl -X POST localhost:9000/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin@example.com","password":"admin123"}'
```

**Response**
```json
{
    "status": "B1",
    "message": "Success",
    "data": {
        "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJkYXRhIjp7ImVtYWlsIjoiYWRtaW5AZXhhbXBsZS5jb20iLCJpZCI6IjAxOWViOTYwLWEyN2QtNzNjOC05NzAzLWIyM2E5ZjUwZGM4MyJ9LCJleHAiOjE3ODEzMzA3MjIsImlhdCI6MTc4MTMxMjcyMn0.kcA15jvQyDfOKHH6M3jPIKQV-uYRHRQ7Jhzunl5QtKg",
        "user": {
            "id": "019eb960-a27d-73c8-9703-b23a9f50dc83",
            "name": "Admin",
            "username": "admin",
            "email": "admin@example.com",
            "is_active": true,
            "roles": [
                {
                    "id": 1,
                    "name": "superadmin"
                }
            ]
        },
        "permissions": [
            "root"
        ]
    }
}
```

### List Roles (dengan token)

```bash
curl localhost:9000/accesses \
  -H "Authorization: Bearer <token>"
```

**Response**

```json
{
    "status": "B1",
    "message": "Success",
    "data": [
        {
            "id": 4,
            "name": "accesses",
            "alias": "accesses",
            "childrens": [
                {
                    "id": 6,
                    "parent_id": 4,
                    "name": "GET /accesses",
                    "alias": "accesses::list"
                }
            ]
        },
        {
            "id": 5,
            "name": "roles",
            "alias": "roles",
            "childrens": [
                {
                    "id": 11,
                    "parent_id": 5,
                    "name": "DELETE /roles/{id}",
                    "alias": "roles::delete"
                },
                {
                    "id": 13,
                    "parent_id": 5,
                    "name": "DELETE /roles/{id}/access/{access_id}",
                    "alias": "roles::revoke"
                },
                {
                    "id": 7,
                    "parent_id": 5,
                    "name": "GET /roles",
                    "alias": "roles::list"
                },
                {
                    "id": 9,
                    "parent_id": 5,
                    "name": "GET /roles/{id}",
                    "alias": "roles::view"
                },
                {
                    "id": 8,
                    "parent_id": 5,
                    "name": "POST /roles",
                    "alias": "roles::create"
                },
                {
                    "id": 12,
                    "parent_id": 5,
                    "name": "POST /roles/{id}/access/{access_id}",
                    "alias": "roles::grant"
                },
                {
                    "id": 10,
                    "parent_id": 5,
                    "name": "PUT /roles/{id}",
                    "alias": "roles::update"
                }
            ]
        },
        {
            "id": 16,
            "name": "users",
            "alias": "users",
            "childrens": [
                {
                    "id": 41,
                    "parent_id": 16,
                    "name": "DELETE /users/{id}",
                    "alias": "users::delete"
                },
                {
                    "id": 18,
                    "parent_id": 16,
                    "name": "GET /users",
                    "alias": "users::list"
                },
                {
                    "id": 39,
                    "parent_id": 16,
                    "name": "GET /users/{id}",
                    "alias": "users::view"
                },
                {
                    "id": 38,
                    "parent_id": 16,
                    "name": "POST /users",
                    "alias": "users::create"
                },
                {
                    "id": 40,
                    "parent_id": 16,
                    "name": "PUT /users/{id}",
                    "alias": "users::update"
                }
            ]
        }
    ]
}
```

### Akses tanpa permission (Forbidden)

```json
{
    "status": "E003",
    "message": "Forbidden",
    "data": {}
}
```

## Ringkasan Bab 18

Di bab ini kita telah belajar:

| Komponen | File | Fungsi |
|----------|------|--------|
| Database | migration/*_rbac.sql | Tabel access, roles, relasi many-to-many |
| Model | model/access.go, role.go, user.go | Struct dengan relasi |
| Repository | access_repository.go, role_repository.go, user_repository.go | Query dengan JSON aggregation |
| Service | accesses.go, roles.go, auths.go | Logika bisnis, permission checking dan handling database transaction |
| Handler | access_handler.go, role_handler.go | HTTP handlers |
| Middleware | auth_middleware.go | JWT + Permission check |
| CLI | cli.go (scan-access) | Auto-register routes ke database |

Manfaat yang kita peroleh:
- ✅ Otorisasi berbasis peran (Role-Based Access Control)
- ✅ Akses hierarkis (root → group → endpoint)
- ✅ Auto-scan routing untuk registrasi access
- ✅ Permission checking di middleware
- ✅ Login response mencakup user info dan permissions

Yang akan datang:
- Saat ini list users dan roles belum mendukung pagination
- Bab selanjutnya: Pagination – membatasi jumlah data per response