# Token

Membuat token auth menggunakan jwt token.

* Tambahkan TOKEN\_SALT environtment pada file .env

```text
APP_PORT=9000
APP_ENV=local

DB_DRIVER=mysql
DB_SOURCE=root:pass@tcp(localhost:3306)/go-services?parseTime=true

TOKEN_SALT=secret-salt
```

* Membuat token library pada libraries/token/token.go

```text
package token

import (
    "os"
    "time"

    jwt "github.com/dgrijalva/jwt-go"
)

// MyCustomClaims struct
type MyCustomClaims struct {
    Username string `json:"username"`
    jwt.StandardClaims
}

var mySigningKey = []byte(os.Getenv("TOKEN_SALT"))

// ValidateToken for check token validation
func ValidateToken(myToken string) (bool, string) {
    token, err := jwt.ParseWithClaims(myToken, &MyCustomClaims{}, func(token *jwt.Token) (interface{}, error) {
        return []byte(mySigningKey), nil
    })

    if err != nil {
        return false, ""
    }

    claims := token.Claims.(*MyCustomClaims)
    return token.Valid, claims.Username
}

// ClaimToken function
func ClaimToken(username string) (string, error) {
    claims := MyCustomClaims{
        username,
        jwt.StandardClaims{
            ExpiresAt: time.Now().Add(time.Hour * 5).Unix(),
        },
    }

    token := jwt.NewWithClaims(jwt.SigningMethodHS256, claims)

    // Sign the token with our secret
    return token.SignedString(mySigningKey)
}
```

* Tambahkan  login routing pada  routing/route.go

```text
    // Auth Routing
    {
        auth := controllers.Auths{Db: db, Log: log}
        app.Handle(http.MethodPost, "/login", auth.Login)
    }
```

* Buat controllers auths pada controllers/auths.go

```text
package controllers

import (
    "database/sql"
    "essentials/libraries/api"
    "essentials/libraries/token"
    "essentials/models"
    "essentials/payloads/request"
    "essentials/payloads/response"
    "fmt"
    "log"
    "net/http"

    "golang.org/x/crypto/bcrypt"
)

// Auths struct
type Auths struct {
    Db  *sql.DB
    Log *log.Logger
}

// Login http handler
func (u *Auths) Login(w http.ResponseWriter, r *http.Request) {
    var loginRequest request.LoginRequest
    err := api.Decode(r, &loginRequest, true)
    if err != nil {
        u.Log.Printf("ERROR : %+v", err)
        api.ResponseError(w, err)
        return
    }

    uLogin := models.User{Username: loginRequest.Username}
    err = uLogin.GetByUsername(r.Context(), u.Db)
    if err != nil {
        u.Log.Printf("ERROR : %+v", err)
        api.ResponseError(w, fmt.Errorf("call login: %v", err))
        return
    }

    err = bcrypt.CompareHashAndPassword([]byte(uLogin.Password), []byte(loginRequest.Password))
    if err != nil {
        u.Log.Printf("ERROR : %+v", err)
        api.ResponseError(w, api.ErrBadRequest(fmt.Errorf("compare password: %v", err), ""))
        return
    }

    token, err := token.ClaimToken(uLogin.Username)
    if err != nil {
        u.Log.Printf("ERROR : %+v", err)
        api.ResponseError(w, fmt.Errorf("claim token: %v", err))
        return
    }

    var response response.TokenResponse
    response.Token = token

    api.ResponseOK(w, response, http.StatusOK)
}
```

* Buat payload login request pada payloads/request/login\_request.go

```text
package request

//LoginRequest : format json request for login
type LoginRequest struct {
    Username string `json:"username"  validate:"required"`
    Password string `json:"password"  validate:"required"`
}
```

* Buat GetByUsername method pada models/user.go

```text
// GetByUsername : get user by username
func (u *User) GetByUsername(ctx context.Context, db *sql.DB) error {
    const q string = `SELECT id, username, password, email, is_active FROM users`
    err := db.QueryRowContext(ctx, q+" WHERE username=?", u.Username).Scan(&u.ID, &u.Username, &u.Password, &u.Email, &u.IsActive)

    if err == sql.ErrNoRows {
        err = api.ErrNotFound(err, "")
    }

    return err
}
```

* Buat payload token response pada payloads/response/token\_response.go

```text
package response

//TokenResponse : format json response for token
type TokenResponse struct {
    Token string `json:"token"`
}
```

* Buat api test untuk login. Buat file controllers/tests/authstest.go

```text
package tests

import (
    "encoding/json"
    "net/http"
    "net/http/httptest"
    "strings"
    "testing"
)

// Auths : struct for set AUths Dependency Injection
type Auths struct {
    App   http.Handler
    Token string
}

// Login : http handler for login
func (u *Auths) Login(t *testing.T) {
    jsonBody := `
        {
            "username": "jackyhtg", 
            "password": "12345678"
        }
    `
    body := strings.NewReader(jsonBody)

    req := httptest.NewRequest("POST", "/login", body)
    req.Header.Set("Content-Type", "application/json")
    resp := httptest.NewRecorder()

    u.App.ServeHTTP(resp, req)

    if resp.Code != http.StatusOK {
        t.Fatalf("getting: expected status code %v, got %v", http.StatusOK, resp.Code)
    }

    var list map[string]interface{}
    if err := json.NewDecoder(resp.Body).Decode(&list); err != nil {
        t.Fatalf("decoding: %s", err)
    }

    u.Token = list["data"].(map[string]interface{})["token"].(string)

}
```

* Update tests/main\_test.go untuk mengetes login

```text
package tests

import (
    apiTest "essentials/controllers/tests"
    "essentials/routing"
    "essentials/schema"
    "log"
    "os"
    "testing"

    _ "github.com/go-sql-driver/mysql"
)

var token string

func TestMain(t *testing.T) {

    db, teardown := NewUnit(t)
    defer teardown()

    if err := schema.Seed(db); err != nil {
        t.Fatal(err)
    }

    log := log.New(os.Stderr, "TEST : ", log.LstdFlags|log.Lmicroseconds|log.Lshortfile)

    // api test for auths
    {
        auths := apiTest.Auths{App: routing.API(db, log)}
        t.Run("ApiLogin", auths.Login)
        token = auths.Token
    }

    // api test for users
    {
        users := apiTest.Users{App: routing.API(db, log)}
        t.Run("APiUsersList", users.List)
        t.Run("APiUsersCrud", users.Crud)
    }
}
```

