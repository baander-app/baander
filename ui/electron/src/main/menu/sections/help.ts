import type { SectionFactory } from '../types';
import { MenuActionId } from '../ids';

export const helpSection: SectionFactory = ({ t }) => [
  {
    label: t('menu.help._'),
    submenu: [
      { id: MenuActionId.HelpDocs, label: t('menu.help.documentation') },
      { id: MenuActionId.HelpReportIssue, label: t('menu.help.reportIssue') },
    ],
  },
];
