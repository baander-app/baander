/*
 * Runs a command with transparent huge pages disabled for it and every process
 * it starts.
 *
 * PGroonga loads Groonga and its bundled libraries into each backend after the
 * fork, and they reserve large anonymous mappings that they touch sparsely.
 * On a host whose THP mode is "always", the kernel may back each touched 2 MiB
 * range with a whole huge page at fault time, or khugepaged may collapse it
 * later, so an idle backend can hold tens of MiB it never used. Inside a memory
 * cgroup that is enough to OOM-kill PostgreSQL once a few pooled connections
 * have used a PGroonga index.
 *
 * PR_SET_THP_DISABLE is inherited across fork(2) and preserved across
 * execve(2), so setting it before the image entrypoint covers the postmaster
 * and every backend. It does not affect hugetlbfs pages requested through
 * PostgreSQL's huge_pages setting.
 */
#define _GNU_SOURCE
#include <errno.h>
#include <stdio.h>
#include <string.h>
#include <sys/prctl.h>
#include <unistd.h>

int main(int argc, char **argv)
{
    if (argc < 2) {
        fprintf(stderr, "usage: %s command [argument...]\n", argv[0]);
        return 64;
    }
    if (prctl(PR_SET_THP_DISABLE, 1, 0, 0, 0) != 0) {
        fprintf(stderr, "%s: prctl(PR_SET_THP_DISABLE) failed: %s\n", argv[0], strerror(errno));
        return 70;
    }
    execvp(argv[1], argv + 1);
    fprintf(stderr, "%s: cannot run %s: %s\n", argv[0], argv[1], strerror(errno));
    return errno == ENOENT ? 127 : 126;
}
