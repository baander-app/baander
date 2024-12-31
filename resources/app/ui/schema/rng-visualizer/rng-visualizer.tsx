import React, { useMemo, useRef } from 'react';
import { Circle, Group, Layer, Line, Rect, Stage, Text } from 'react-konva';
import { hierarchy, Tree } from '@visx/hierarchy';
import { Zoom } from '@visx/zoom';
import { NodeProps } from '@visx/hierarchy/lib/HierarchyDefaultNode';
import { useSize } from 'ahooks';

const peach = '#fd9b93';
const pink = '#fe6e9e';
const blue = '#03c0dc';
const green = '#26deb0';
const plum = '#71248e';
const lightpurple = '#374469';
const white = '#ffffff';
const background = '#eea947';

interface TreeNode {
  name: string;
  attributes: Record<string, string>;
  children?: TreeNode[];
}

interface RngVisualizerProps {
  data: TreeNode;
  margin?: { top: number; right: number; bottom: number; left: number };
}

interface HierarchyNode extends NodeProps {
  data: TreeNode;
  depth: number;
  height: number;
  parent: HierarchyNode;
  x: number;
  y: number;
}

const defaultMargin = { top: 50, left: 100, right: 100, bottom: 50 };

const NodeLabel = ({ node }: { node: HierarchyNode }) => {
  const attributes = typeof node.data.attributes === 'object'
                     ? Object.entries(node.data.attributes)
                             .map(([key, value]) => `${key}: ${value}`)
                             .join(', ')
                     : '';

  return (
    <>
      <Text text={node.data.name} fontSize={9} fontFamily="Arial" fill={plum} y={-20} align="center" />
      <Text text={attributes} fontSize={9} fontFamily="Arial" fill={white} y={0} align="center" />
    </>
  );
};

const RootNode = ({ node }: { node: HierarchyNode }) => (
  <Group x={node.x} y={node.y}>
    <Circle radius={12}
            fillLinearGradientStartPoint={{ x: -12, y: 0 }}
            fillLinearGradientEndPoint={{ x: 12, y: 0 }}
            fillLinearGradientColorStops={[0, peach, 1, pink]} />
    <NodeLabel node={node} />
  </Group>
);

const ParentNode = ({ node }: { node: HierarchyNode }) => (
  <Group x={node.x} y={node.y}>
    <Rect
      width={80}
      height={40}
      offsetX={40}
      offsetY={20}
      fill={background}
      stroke={blue}
      strokeWidth={1}
    />
    <NodeLabel node={node} />
  </Group>
);

const LeafNode = ({ node }: { node: HierarchyNode }) => {
  console.log(node);

  return (
    <Group x={node.x} y={node.y}>
      <Rect
        width={80}
        height={60}
        offsetX={40}
        offsetY={20}
        fill={background}
        stroke={green}
        strokeWidth={1}
        dash={[4, 4]}
        strokeOpacity={0.6}
        cornerRadius={5}
      />
      <NodeLabel node={node}/>
    </Group>
  );
};

const Node = ({ node }: { node: HierarchyNode }) => {
  if (node?.depth === 0) return <RootNode node={node} />;
  if (node?.children) return <ParentNode node={node} />;
  return <LeafNode node={node} />;
};

const initialTransform = {
  scaleX: 1.27,
  scaleY: 1.27,
  translateX: -211.62,
  translateY: 162.59,
  skewX: 0,
  skewY: 0,
};

export function RngVisualizer({
                                data,
                                margin = defaultMargin,
                              }: RngVisualizerProps): JSX.Element | null {
  const root = useMemo(() => hierarchy(data), [data]);
  const ref = useRef<HTMLDivElement>(null);
  const size = useSize(ref);
  const xMax = (size && size.width - margin.left - margin.right) || 50;
  const yMax = (size && size.height - margin.top - margin.bottom) || 50;


  if (!size || size.width < 10 || size.height < 10) {
    // return null;
  }

  return (
    <div ref={ref} style={{ width: '100%', height: 'inherit', border: '1px solid red' }}>
      <Stage width={size?.width ?? 1000} height={size?.height ?? 1000}>
        <Layer>
          <Zoom
            width={size?.width ?? 100}
            height={size?.height ?? 100}
            scaleXMin={0.5}
            scaleXMax={4}
            scaleYMin={0.5}
            scaleYMax={4}
            initialTransformMatrix={initialTransform}
          >
            {(zoom) => (
              <Group
                x={margin.left}
                y={margin.top}
                scaleX={zoom.transformMatrix.scaleX}
                scaleY={zoom.transformMatrix.scaleY}
                offsetX={zoom.transformMatrix.translateX}
                offsetY={zoom.transformMatrix.translateY}
              >
                <Tree<TreeNode> root={root} size={[xMax, yMax]} nodeSize={[200, 75]}>
                  {(tree) => (
                    <>
                      {tree.links().map((link, i) => (
                        <Line
                          key={`link-${i}`}
                          points={[link.source.x, link.source.y, link.target.x, link.target.y]}
                          stroke={lightpurple}
                          strokeWidth={1}
                        />
                      ))}
                      {tree.descendants().map((node, i) => (
                        <Node key={`node-${i}`} node={node}/>
                      ))}
                    </>
                  )}
                </Tree>
              </Group>
            )}
          </Zoom>
        </Layer>
      </Stage>
    </div>
  );
}