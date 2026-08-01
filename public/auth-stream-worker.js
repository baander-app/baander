(() => {
  let accessToken = null;
  let dpopNonce = null;
  let apiUrl = null;
  self.addEventListener("install", () => {
    self.skipWaiting();
  });
  self.addEventListener("activate", (event) => {
    event.waitUntil(
      self.clients.claim().then(() => {
        return self.clients.matchAll().then((clients) => {
          clients.forEach((client) => client.postMessage({ type: "SW_REQUEST_TOKEN" }));
        });
      })
    );
  });
  self.addEventListener("message", (event) => {
    if (event.data?.type === "SW_SET_TOKEN") {
      accessToken = event.data.token;
    }
    if (event.data?.type === "SW_SET_API_URL") {
      apiUrl = event.data.apiUrl;
    }
  });
  function buildHtu(url) {
    try {
      const baseUrl = apiUrl ?? self.location.origin;
      const parsed = new URL(url, baseUrl);
      return `https://${parsed.host}${parsed.pathname}`;
    } catch {
      return url;
    }
  }
  function requestDpopProof(method, url, token) {
    return new Promise((resolve) => {
      const timeout = setTimeout(() => resolve(null), 3e3);
      const nonce = dpopNonce;
      const channel = new MessageChannel();
      channel.port1.onmessage = (event) => {
        clearTimeout(timeout);
        if (event.data?.type === "SW_DPOP_PROOF" && typeof event.data.proof === "string") {
          if (event.data.nonce) {
            dpopNonce = event.data.nonce;
          }
          resolve(event.data.proof);
        } else {
          resolve(null);
        }
      };
      self.clients.matchAll().then((clients) => {
        if (clients.length === 0) {
          clearTimeout(timeout);
          resolve(null);
          return;
        }
        const client = clients[0];
        client.postMessage(
          { type: "SW_SIGN_DPOP", method, url, nonce: nonce ?? void 0 },
          [channel.port2]
        );
      }).catch(() => {
        clearTimeout(timeout);
        resolve(null);
      });
    });
  }
  self.addEventListener("fetch", (event) => {
    const url = new URL(event.request.url);
    if (!url.pathname.startsWith("/api/stream/") && !url.pathname.startsWith("/api/images/")) {
      return;
    }
    if (!accessToken) {
      return;
    }
    event.respondWith((async () => {
      const htu = buildHtu(url.toString());
      const proof = await requestDpopProof(event.request.method, htu, accessToken);
      if (!proof) {
        return fetch(event.request);
      }
      const headers = new Headers(event.request.headers);
      headers.set("Authorization", `DPoP ${accessToken}`);
      headers.set("DPoP", proof);
      const response = await fetch(event.request, { headers });
      const nonce = response.headers.get("dpop-nonce");
      if (nonce) {
        dpopNonce = nonce;
      }
      if (response.status === 401) {
        self.clients.matchAll().then((clients) => {
          clients.forEach((client) => client.postMessage({ type: "SW_AUTH_EXPIRED" }));
        });
      }
      return response;
    })());
  });
})();
