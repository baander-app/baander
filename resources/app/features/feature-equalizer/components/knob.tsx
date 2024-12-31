import React, { useState, useEffect } from 'react';
import styles from './knob.module.scss';

export interface KnobProps {
  [key: string]: any;
  name: string;
  leftLabel: string;
  rightLabel: string;
  isEnabled?: boolean;
  isSmall?: boolean;
  value?: number;
  isInfinite?: boolean;
  isIndicatorsVisible?: boolean;
  onChange?: (value: number) => void;
}

export function Knob(props: KnobProps) {
  const {
    isEnabled,
    name,
    leftLabel,
    rightLabel,
    value,
    isIndicatorsVisible,
    onChange,
    ...rest
  } = props;
  const [deg, setDeg] = useState(0);

  const handleOnWheelChange = (e: React.WheelEvent<HTMLDivElement>) => {
    const { deltaY } = e;

    const newRotate = deg + deltaY;

    if (newRotate >= 0 && newRotate <= 270) {
      setDeg(newRotate);
    }

    const value = Math.round((newRotate / 270) * 100);
    const valueWithLimitedRange = value > 100 ? 100 : value <= 0 ? 0 : value;

    if (isEnabled) {
      onChange?.(valueWithLimitedRange);
    }
  };

  useEffect(() => {
    const newRotate = Math.round(((value ?? 0) / 100) * 270);
    setDeg(newRotate);
  }, [value]);

  return (
    <div className={styles.eqKnob} {...rest}>
      <p className={styles.eqKnobName}>{name}</p>
      <div className={styles.eqKnobControl}>
        <div className={styles.eqKnobRevolveControlContainer}>
          <div
            className={`${styles.eqKnobRevolveControl} ${styles.rotating}`}
            style={{ '--rotate': `${deg}deg` } as React.CSSProperties}
            title="Use mouse wheel to control"
            onWheel={(e) => handleOnWheelChange(e)}
          >
            {!isIndicatorsVisible && (
              <div
                className={`${styles.eqKnobRevolvePointer} ${
                  isEnabled ? styles.active : ''
                }`}
              />
            )}
          </div>
          {isIndicatorsVisible &&
            Array.from({ length: 10 }, (_, index) => (
              <div
                className={styles.egDotContainer}
                key={index}
                style={{ '--rotate': `${index * 30 + 45}deg` } as React.CSSProperties}
              >
                <div
                  className={`${styles.eqDot} ${
                    isEnabled && index * 30 <= deg ? styles.active : ''
                  }`}
                />
              </div>
            ))}
        </div>
      </div>
      <div className={styles.eqKnobLabels}>
        <p className={styles.eqKnobLabel}>{leftLabel}</p>
        <p className={styles.eqKnobLabel}>{rightLabel}</p>
      </div>
    </div>
  );
}