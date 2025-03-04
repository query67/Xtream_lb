#ifndef TSRM_CONFIG_COMMON_H
#define TSRM_CONFIG_COMMON_H

#ifndef __CYGWIN__
#ifdef _WIN32
#define TSRM_WIN32
#endif
#endif

#ifdef TSRM_WIN32
#include "tsrm_config.w32.h"
#else
#include <sys/param.h>
#include <tsrm_config.h>
#endif

#if HAVE_ALLOCA_H && !defined(_ALLOCA_H)
#include <alloca.h>
#endif

/* AIX requires this to be the first thing in the file.  */
#ifndef __GNUC__
#ifndef HAVE_ALLOCA_H
#ifdef _AIX
#pragma alloca
#else
#ifndef alloca /* predefined by HP cc +Olibcalls */
char *alloca();
#endif
#endif
#endif
#endif

#if HAVE_UNISTD_H
#include <unistd.h>
#endif

#if HAVE_LIMITS_H
#include <limits.h>
#endif

#ifndef MAXPATHLEN
#if _WIN32
#include "win32/ioutil.h"
#define MAXPATHLEN PHP_WIN32_IOUTIL_MAXPATHLEN
#elif PATH_MAX
#define MAXPATHLEN PATH_MAX
#elif defined(MAX_PATH)
#define MAXPATHLEN MAX_PATH
#else
#define MAXPATHLEN 256
#endif
#endif

#if (HAVE_ALLOCA || (defined(__GNUC__) && __GNUC__ >= 2))
#define TSRM_ALLOCA_MAX_SIZE 4096
#define TSRM_ALLOCA_FLAG(name) int name;
#define tsrm_do_alloca_ex(size, limit, use_heap) \
  ((use_heap = ((size) > (limit))) ? malloc(size) : alloca(size))
#define tsrm_do_alloca(size, use_heap) \
  tsrm_do_alloca_ex(size, TSRM_ALLOCA_MAX_SIZE, use_heap)
#define tsrm_free_alloca(p, use_heap) \
  do                                  \
  {                                   \
    if (use_heap)                     \
      free(p);                        \
  } while (0)
#else
#define TSRM_ALLOCA_FLAG(name)
#define tsrm_do_alloca(p, use_heap) malloc(p)
#define tsrm_free_alloca(p, use_heap) free(p)
#endif

#endif /* TSRM_CONFIG_COMMON_H */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: sw=4 ts=4 fdm=marker
 * vim<600: sw=4 ts=4
 */
