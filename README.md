# LDAPaaS - LDAP as a Service

This compact implementation allows simple orchestration of LDAP instances.

## Requirements

 - PHP
 - Zend Framework 1.12
 - Apache or compatible (lighttpd, Zend Server)
 - d389 or Redhat Directory Server

## Configuration

LDAPaaS uses `defaults.ini` for defaults, and `config.ini` to override them. You should not have to modify `defaults.ini`.

Values marked __REQUIRED__ must be specified by `config.ini`. See `defaults.ini` for more information.

## Installation

1. Download the files into a folder inside the webroot. (eg if using `/var/www/html/ldapaas`, then the service will be accessible at `http://servername/ldapaas/`)
2. Ensure that the `AllowOverride` directive for that folder includes at least `AuthConfig` and `FileInfo`. (or `all`) 
3. Create at least one user in the `.htpasswd` file; `htpasswd -c .htpasswd (user)`. Update the `.htaccess` file if installed somewhere other than `/var/www/html/ldapaas`.  
   _(You may use any other kind of authentication, as long as the user information is passed to PHP correctly)_
4. Create a folder for the LDAP instances, and ensure that the web server user has write access to it.
5. Create a `config.ini` file, and specify the needed values. 

## API Data Types

### Instance

An __Instance__ object specifies relevant details of an LDAP instance.

For example:

```
{
  "name":"testuser20000",
  "user":"testuser",
  "port":"20000",
  "base_dn":"o=example.com",
  "password":"MWQ3MjY5NzUyNGU"
}
```

#### Schema
 
 - name: 'Name' of the instance. Used to identify the LDAP instance when reading or removing it.
 - user: The API user that created the instance.
 - port: Port number the LDAP instance listens on.
 - base_dn: The base DN specified when creating the instance.
 - password: A randomly generated password, used for the admin and `cn=Directory Manager` accounts.

### Error

If an error occurs in any of the following API calls, an __Error__ object will be returned.

For example:

```
{
  "error":"Invalid user", "
  code":403
}
```

## API Usage

The API is intentionally very simple. Once an LDAP instance is started, most changes can be made through LDAP.

LDAP instances are created and started, or stopped and deleted. There is no provision for a managing inactive LDAP instances. 

### PUT /

#### Parameters
 - base_dn (eg o=example.com)

#### Description
Create and start an LDAP instance.

#### Response
Returns the __Instance__ object for the newly created LDAP instance.

### GET /<NAME>

#### Parameters
  - name (eg testuser20000)

#### Description
Read information about that LDAP instance.

#### Response
Returns that __Instance__ object.

### DELETE /<NAME>

#### Parameters
  - name (eg testuser20000)

#### Description
Stop and remove that LDAP instance.

All records and associated files will be removed.

#### Response
Returns a `{"success":true}` object.

### GET /

#### Parameters
  _None_
  
#### Description
Read information about every LDAP instance the user has.

#### Response
Returns an map containing `{ "<name>":Instance, "<name>":Instance }`

### DELETE /

#### Parameters
  _None_
  
#### Description
Delete every LDAP instance that user has.

#### Response
Returns an map containing `{ "<name>":{"success":true}, "<name>":{"success":true} }`






