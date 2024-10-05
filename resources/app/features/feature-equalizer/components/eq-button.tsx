import styles from './eq-button.module.scss';

export interface EqButtonProps {
  [key: string]: any;
  handleOnClick: () => void;
  label: string;
}

export function EqButton(props: EqButtonProps) {
  const { handleOnClick, label, ...rest } = props;
  return (
    <div className={styles.eqButtonContainer} {...rest}>
      <p className={styles.eqPowerLabel}>{label}</p>
      <button className={styles.eqPowerButton} onClick={() => handleOnClick()} />
    </div>
  );
}