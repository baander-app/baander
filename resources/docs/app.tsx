import React from 'react';
import { Stoplight } from './components/stoplight';

const App = () => {
  return (
    <>
      <Stoplight apiDescriptionUrl={window.BaanderAppConfig.apiDocsUrl} />
    </>
  )
}

export default App;