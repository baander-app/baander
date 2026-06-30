import styled from 'styled-components';

const Bar = styled.div`
  display: flex;
  align-items: center;
  justify-content: flex-end;
  height: 32px;
  padding: 0 8px;
  -webkit-app-region: drag;
  user-select: none;
  background-color: var(--color-sidebar);
`;

const Controls = styled.div`
  display: flex;
  gap: 8px;
  -webkit-app-region: no-drag;
`;

const Btn = styled.button`
  width: 12px;
  height: 12px;
  border-radius: 50%;
  border: none;
  cursor: pointer;
  padding: 0;
  &:hover { opacity: 0.8; }
`;

const MinimizeBtn = styled(Btn)`
  background-color: var(--color-chart-4);
`;

const MaximizeBtn = styled(Btn)`
  background-color: var(--color-chart-3);
`;

const CloseBtn = styled(Btn)`
  background-color: var(--color-destructive);
`;

/** Detect macOS (native traffic lights preserved via titleBarStyle: hidden) */
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent);

export function TitleBar() {
  return (
    <Bar>
      {/* Only render custom controls on Windows/Linux — macOS uses native traffic lights */}
      {!isMac && (
        <Controls>
          <MinimizeBtn onClick={() => window.BaanderWindow?.minimize?.()} aria-label="Minimize" />
          <MaximizeBtn onClick={() => window.BaanderWindow?.maximize?.()} aria-label="Maximize" />
          <CloseBtn onClick={() => window.BaanderWindow?.close?.()} aria-label="Close" />
        </Controls>
      )}
    </Bar>
  );
}
