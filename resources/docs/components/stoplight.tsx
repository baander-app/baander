import React from 'react';
import '@stoplight/elements/styles.min.css';
import '@stoplight/elements/web-components.min.js'

export interface StoplightProps {
  apiDescriptionUrl?: string;
  tryItCredentialsPolicy?: 'omit' | 'include' | 'same-origin';
  router?: 'history' | 'hash' | 'memory';
  logo?: string;
}

export const Stoplight = ({
  apiDescriptionUrl = '/docs/api.json',
  tryItCredentialsPolicy = 'same-origin',
  router = 'memory',
  logo = '/baander-logo.svg',
}: StoplightProps) => {
  return (
    <div style={{ height: '100vh', width: '100%' }}>
      <elements-api
        id="docs"
        apiDescriptionUrl={apiDescriptionUrl}
        tryItCredentialsPolicy={tryItCredentialsPolicy}
        router={router}
        logo={logo}
      />
    </div>
  );
};