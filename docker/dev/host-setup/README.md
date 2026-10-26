# Local hostname setup

Inspect these files before installing them. NetworkManager must own DNS on this
host. Copy `10-baander-dns.conf` to
`/etc/NetworkManager/conf.d/10-baander-dns.conf` and `baander.conf` to
`/etc/NetworkManager/dnsmasq.d/baander.conf` with administrator permission.
Reload NetworkManager during a convenient interruption; DNS changes may briefly
interrupt network access. Verify `getent hosts baander.test` and a preview hostname
both resolve to `127.0.0.1`.

Invoke the workflow's certificate setup explicitly. Trust installation invokes
`mkcert -install` and can request administrator permission. The developer CA stays
in private host state. Only the leaf certificate and leaf key enter the gateway.
The gateway publishes port 443 on loopback. Stop any previous listener before its
first startup; Docker must support publishing that port for this user.
