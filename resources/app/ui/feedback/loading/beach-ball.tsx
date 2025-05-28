import { motion } from 'motion/react';
import { CSSProperties } from 'react';

export const BeachBall = () => {
  const transitionValues = {
    duration: 0.8,
    yoyo: Infinity,
    ease: 'easeOut',
  };

  // @ts-expect-error
  const ballStyle = {
    display: 'block',
    width: '5rem',
    height: '5rem',
    borderRadius: '50%',
    margin: 'auto',
    position: 'relative',
    background: 'conic-gradient(red, yellow, green, blue, red)',
    boxShadow: '0 3px 6px rgba(0, 0, 0, 0.2)',
  };

  const highlightStyle: CSSProperties = {
    position: 'absolute',
    top: '10%',
    left: '10%',
    width: '30%',
    height: '30%',
    background: 'rgba(255, 255, 255, 0.6)',
    borderRadius: '50%',
    filter: 'blur(5px)',
    pointerEvents: 'none',
  };

  return (
    <motion.div
      // style={ballStyle}
      transition={transitionValues}
      animate={{
        rotate: [0, 360],
      }}
    >
      <span style={highlightStyle}/>
    </motion.div>
  );
};