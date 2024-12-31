import LogoComponent from '../../../assets/svg/baander-logo.svg?component';
import styles from './baander-logo.module.scss';

export function BaanderLogo() {
  return (
    <>
      <LogoComponent className={styles.baanderLogo} height="50px" />
    </>
  )
}