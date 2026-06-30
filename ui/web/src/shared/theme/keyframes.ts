import { css } from 'styled-components';

export const keyframes = css`
  @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
  @keyframes fadeOut { from { opacity: 1; } to { opacity: 0; } }
  @keyframes zoomIn95 { from { opacity: 0; transform: translate(-50%, -50%) scale(0.95); } to { opacity: 1; transform: translate(-50%, -50%) scale(1); } }
  @keyframes zoomOut95 { from { opacity: 1; transform: translate(-50%, -50%) scale(1); } to { opacity: 0; transform: translate(-50%, -50%) scale(0.95); } }
  @keyframes slideInFromTop { from { transform: translateY(-10px); } to { transform: translateY(0); } }
  @keyframes slideOutToTop { from { transform: translateY(0); } to { transform: translateY(-10px); } }
  @keyframes slideInFromBottom { from { transform: translateY(10px); } to { transform: translateY(0); } }
  @keyframes slideOutToBottom { from { transform: translateY(0); } to { transform: translateY(10px); } }
  @keyframes slideInFromLeft { from { transform: translateX(-10px); } to { transform: translateX(0); } }
  @keyframes slideOutToLeft { from { transform: translateX(0); } to { transform: translateX(-10px); } }
  @keyframes slideInFromRight { from { transform: translateX(10px); } to { transform: translateX(0); } }
  @keyframes slideOutToRight { from { transform: translateX(0); } to { transform: translateX(10px); } }
  @keyframes equalizerBar { 0%, 100% { transform: scaleY(0.4); } 50% { transform: scaleY(1); } }
`;
