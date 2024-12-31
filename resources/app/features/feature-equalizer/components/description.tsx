import styles from './description.module.scss';

export interface DescriptionProps {
  [key: string]: any;
  label: string;
}

export function Description(props: DescriptionProps) {
  const { label, ...rest } = props;

  return (
    <div className={styles.descriptionContainer} {...rest}>
      <div className={styles.horizontalLine} />
      <span className={styles.label}>{label}</span>
      <div className={styles.horizontalLine} />
    </div>
  );
}